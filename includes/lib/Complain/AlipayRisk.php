<?php
namespace lib\Complain;

use Exception;

class AlipayRisk implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
        $alipay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
        $this->service = new \Alipay\AlipayComplainService($alipay_config);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);

        $count_add = 0;
        $count_update = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            try{
                $result = $this->service->riskbatchQuery(null, null, null, $page_num, $page_size);
            } catch (Exception $e) {
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
            if($result['total_size'] == 0 || count($result['complaint_list']) == 0) break;

            foreach($result['complaint_list'] as $info){
                $retcode = $this->updateInfo($info);
                if($retcode == 2) $count_update++;
                elseif($retcode == 1) $count_add++;

                if(isset($_GET['key']) && self::getStatus($info['status']) < 2){ //监控模式
                    global $DB;
                    $msgtype = null;
                    if($retcode == 2){
                        $msgtype = '用户提交了新的反馈，请尽快处理';
                    }elseif($retcode == 1){
                        $msgtype = '您有新的支付交易投诉，请尽快处理';
                    }
                    if($msgtype){
                        CommUtil::sendMsg($msgtype, $info['id']);
                    }
                }
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条投诉记录，更新'.$count_update.'条投诉记录'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        return;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        try{
            $info = $this->service->riskquery($data['thirdid']);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        
        $status = self::getStatus($info['status']);
        if($status != $data['status']){
            $data['edittime'] = $info['gmt_process'];
            $DB->update('complain', ['status'=>$status, 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
            $data['status'] = $status;
        }

        $data['money'] = $info['complain_amount'];
        $data['complain_url'] = $info['complain_url'] ?? '无';
        $data['images'] = $info['certify_info'] ?? [];
        $data['status_text'] = $info['status_description']; //投诉单明细状态
        $data['reply_detail_infos'] = []; //协商记录

        //商家处理进展
        $data['process_code'] = $info['process_code'];
        $data['process_message'] = $info['process_message'];
        $data['process_remark'] = $info['process_remark'];
        $data['process_img_url_list'] = $info['process_img_url_list'] ?? [];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }

    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['id'];
        $status = self::getStatus($info['status']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $trade_no_list = [];
            foreach($info['complaint_trade_info_list'] as $item){
                $trade_no = $item['out_no'];
                $api_trade_no = $item['trade_no'];
                if(strlen($trade_no) > 19){
                    $sub_order = $DB->find('suborder', 'trade_no', ['sub_trade_no'=>$trade_no]);
                    if($sub_order){
                        $trade_no = $sub_order['trade_no'];
                    }
                }
                $order = $DB->find('order', 'uid', ['trade_no'=>$trade_no]);
                if($order){
                    $trade_no_list[] = $trade_no;
                }else{
                    $order = $DB->find('order', 'trade_no,uid', ['api_trade_no'=>$api_trade_no]);
                    if($order){
                        $trade_no_list[] = $order['trade_no'];
                    }else{
                        $order = $DB->find('order', 'trade_no,uid', ['bill_trade_no'=>$api_trade_no]);
                        if($order){
                            $trade_no_list[] = $order['trade_no'];
                        }
                    }
                }
            }
            if(!empty($trade_no_list)){
                $trade_no = $trade_no_list[0];
            }else{
                if(!$conf['complain_range']) return 0;
            }
        }

        if($row){
            if($status != $row['status']){
                $DB->update('complain', ['status'=>$status, 'edittime'=>$info['gmt_process']], ['id'=>$row['id']]);
                $trade_no_list = [];
                if($row['trade_no_list']){
                    $trade_no_list = explode(',', $row['trade_no_list']);
                }else{
                    $trade_no_list[] = $row['trade_no'];
                }
                CommUtil::autoHandle($trade_no_list, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'source'=>1, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complain_content'], 'status'=>$status, 'phone'=>$info['contact'], 'addtime'=>$info['gmt_complain'], 'edittime'=>$info['gmt_process'], 'trade_no_list'=>implode(',', $trade_no_list), 'money'=>$info['complain_amount']]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, 'ORTHER', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no_list, $status);
                return 1;
            }
        }
        return 0;
    }

    //上传图片
    public function uploadImage($thirdid, $filepath, $filename){
        try{
            $result = $this->service->riskimageUpload($filepath, $filename);
            $image_id = $result['file_key'] . '|' . $result['file_url'];
            return ['code'=>0, 'image_id'=>$image_id];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        if($images && count($images) > 0){
            $img_file_list = [];
            foreach($images as $image){
                $arr = explode('|', $image);
                $img_file_list[] = ['img_url'=>$arr[1], 'img_url_key'=>$arr[0]];
            }
        }else{
            $img_file_list = null;
        }
        try{
            $this->service->riskfeedbackSubmit($thirdid, $code, $content, $img_file_list);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        try{
            $this->service->riskReplySend($thirdid, $content);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        return false;
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        return false;
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        return false;
    }

    private static function getStatus($status){
        if($status == 'WAIT_PROCESS' || $status == 'OVERDUE'){
            return 0;
        }elseif($status == 'PROCESSING' || $status == 'PART_OVERDUE'){
            return 1;
        }else{
            return 2;
        }
    }

}