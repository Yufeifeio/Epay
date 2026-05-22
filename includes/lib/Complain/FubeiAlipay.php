<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'fubei/inc/FubeiClient.class.php';

class FubeiAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new \FubeiClient($channel['appid'], $channel['appkey']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');

        $params = [
            'merchant_id' => $this->channel['mchid'],
            'gmt_date_begin' => $begin_date,
            'gmt_date_end' => $end_date,
            'status' => -1,
            'page' => 1,
            'page_size' => $limit,
        ];
        try{
            $result = $this->service->execute('openapi.college.alipay.riskgo.complaint.list', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['list'])){
            foreach($result['list'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条投诉记录，更新'.$count_update.'条投诉记录'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        $info = json_decode($type, true);
        $rescode = $this->updateInfo($info);

        $msgtype = null;
        if($rescode == 2){
            $msgtype = '用户提交了新的反馈，请尽快处理';
        }elseif($rescode == 1){
            $msgtype = '您有新的支付交易投诉，请尽快处理';
        }
        if($msgtype){
            CommUtil::sendMsg($msgtype, $thirdid);
        }
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $params = [
            'merchant_id' => $this->channel['mchid'],
            'complaint_id' => $data['thirdid'],
        ];
        try{
            $info = $this->service->execute('openapi.college.alipay.riskgo.complaint.detail', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['risk_go_complaint_info_result']['status']);
        if($status != $data['status']){
            $data['status'] = $status;
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>'NOW()'], ['id'=>$data['id']]);
        }

        $data['money'] = $info['risk_go_complaint_info_result']['amount'];
        $data['phone'] = $info['risk_go_complaint_info_result']['contact'];
        $data['complain_url'] = $info['risk_go_complaint_info_result']['complainUrl'] ?? '无';
        $data['images'] = $info['certifyImgList'] ?? [];
        $data['reply_detail_infos'] = []; //协商记录

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaint_id'];
        $status = self::getStatus($info['status']);
        
        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $trade_no_list = [];
            foreach($info['out_no_list'] as $i => $trade_no){
                $api_trade_no = $info['trade_no_list'][$i];
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
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complain_content'], 'status'=>$status, 'addtime'=>$info['gmt_date'], 'edittime'=>$info['gmt_process_date'], 'trade_no_list'=>implode(',', $trade_no_list), 'money'=>$info['amount']]);

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
        $image = file_get_contents($filepath);
        $params = [
            'bus_type' => 'other',
            'file_data' => base64_encode($image)
        ];
        try{
            $result = $this->service->execute('openapi.agent.base.imgupload.security', $params);
            return ['code'=>0, 'image_id'=>$result['resource_id']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        if($images == null) $images = [];
        $params = [
            'merchant_id' => $this->channel['mchid'],
            'complaint_id' => $thirdid,
            'process_code' => $code,
            'remark' => $content,
            'risk_go_complaint_img_list' => $images,
        ];
        try{
            $this->service->execute('openapi.college.alipay.riskgo.complaint.handle', $params);
            return ['code'=>0];
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
        if($status == 1){
            return 0;
        }elseif($status == 2){
            return 1;
        }else{
            return 2;
        }
    }
}