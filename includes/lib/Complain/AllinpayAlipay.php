<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'allinpay/inc/PayService.class.php';

class AllinpayAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new \PayService($channel['orgid'],$channel['appmchid'],$channel['appid'],$channel['appkey'],$channel['appsecret']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);

        $params = [
            'current_page_num' => 1,
            'page_size' => $limit,
        ];
        $apiurl = 'https://cus.allinpay.com/cusapi/riskfeeback/alicomplaintqueryv2';
        try{
            $result = $this->service->submit($apiurl, $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        //print_r($result);exit;
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['records'])){
            $records = base64_decode($result['records']);
            $records = json_decode($records, true);
            foreach($records['complaint_list'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条投诉记录，更新'.$count_update.'条投诉记录'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $apiurl = 'https://cus.allinpay.com/cusapi/riskfeeback/alicomplaintquery';
        $params = [
            'complain_id' => $data['thirdid'],
        ];
        try{
            $result = $this->service->submit($apiurl, $params);
            $info = json_decode(base64_decode($result['records']), true);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['status']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = $info['gmt_process'];
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
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
                $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['api_trade_no'=>$trade_no]);
                if($order){
                    $trade_no_list[] = $order['trade_no'];
                }else{
                    $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['bill_trade_no'=>$api_trade_no]);
                    if($order){
                        $trade_no_list[] = $order['trade_no'];
                    }else{
                        $trade_no = $api_trade_no;
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
                $DB->update('complain', ['status'=>$status, 'edittime'=>'NOW()'], ['id'=>$row['id']]);
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
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complain_content'], 'status'=>$status, 'phone'=>$info['contact'], 'addtime'=>$info['gmt_complain'], 'edittime'=>$info['gmt_process'], 'trade_no_list'=>implode(',', $trade_no_list), 'money'=>$info['complain_amount']]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, '', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no_list, $status);
                return 1;
            }
        }
        return 0;
    }

    //上传图片
    public function uploadImage($thirdid, $filepath, $filename){
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $apiurl = 'https://cus.allinpay.com/cusapi/riskfeeback/alicomplaintfileupload';
        $params = [
            'file' => new \CURLFile($filepath, self::mime_content_type($ext), $filename),
        ];
        try{
            $result = $this->service->submit($apiurl, $params, true);
            $info = json_decode(base64_decode($result['records']), true);
            return ['code'=>0, 'image_id'=>$info['file_key'] . '|' . $info['file_url']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    private static function mime_content_type($ext)
    {
        $mime_types = [
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
        ];
        return $mime_types[$ext];
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        $apiurl = 'https://cus.allinpay.com/cusapi/riskfeeback/alicomplaintprocessfinish';
        $img_file_list = [];
        if($images && count($images) > 0){
            foreach($images as $image){
                $arr = explode('|', $image);
                $img_file_list[] = ['img_url'=>$arr[1], 'img_url_key'=>$arr[0]];
            }
        }
        $params = [
            'id_list' => json_encode([$thirdid]),
            'process_code' => $code,
            'remark' => $content,
            'img_file_list' => json_encode($img_file_list),
        ];
        try{
            $result = $this->service->submit($apiurl, $params);
            return ['code'=>0, 'result'=>$result['complaint_process_success']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        return false;
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