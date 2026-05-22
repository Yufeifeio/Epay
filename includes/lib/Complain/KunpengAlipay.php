<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'kunpeng/inc/KunpengClient.php';

class KunpengAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \KunpengClient($channel['appid']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        return ['code'=>-1, 'msg'=>'鲲鹏支付不支持拉取投诉列表，只能通过回调更新'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        if(empty($type)) return;
        $retcode = $this->updateInfo($type);

        //发送消息通知
        $msgtype = null;
        if($retcode == 2){
            $msgtype = '用户提交了新的反馈，请尽快处理';
        }elseif($retcode == 1){
            $msgtype = '您有新的支付交易投诉，请尽快处理';
        }
        if($msgtype){
            CommUtil::sendMsg($msgtype, $thirdid);
        }
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $params = [
            'complaintId' => $data['thirdid'],
            'channelType' => 'A',
        ];
        try{
            $info = $this->service->execute('/mas/atComplaint/queryComplaint.do', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['complaintStatus']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = $info['complaintAmount'];
        $data['images'] = [];
        $data['status_text'] = $info['statusDescription']; //投诉单明细状态
        $data['reply_detail_infos'] = []; //协商记录

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintNo'];
        $trade_no = $info['complaintLocalOrderNo'];
        $api_trade_no = $info['complaintChannelOrderNo'];
        $status = self::getStatus($info['complaintStatus']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['bill_trade_no'=>$api_trade_no]);
            if($order){
                $trade_no = $order['trade_no'];
            }else{
                if(!$conf['complain_range']) return 0;
            }
        }

        if($row){
            if($status != $row['status']){
                $DB->update('complain', ['status'=>$status, 'edittime'=>'NOW()'], ['id'=>$row['id']]);
                if($row['status'] == 2 && $status == 1 && $conf['complain_auto_reply'] >= 1 && !empty($conf['complain_auto_reply_con']) && $conf['complain_auto_reply_repeat']==1){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, '', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complaintDescription'], 'status'=>$status, 'phone'=>$info['complaintPhone'], 'addtime'=>$info['complaintCreateTime'], 'edittime'=>$info['complaintCreateTime'], 'thirdmchid'=>$info['complaintChannelMerchantNo'], 'money'=>$info['complaintAmount']]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, '', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no, $status);
                return 1;
            }
        }
        return 0;
    }

    //上传图片
    public function uploadImage($thirdid, $filepath, $filename){
        $params = [
            'file' => new \CURLFile($filepath, null, $filename),
        ];
        try{
            $result = $this->service->execute('/mas/atComplaint/alipayComplaintImgUpload.do', $params, false, true, true);
            return ['code'=>0, 'image_id'=>$result['fileKey'].'|'.$result['fileUrl']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        $params = [
            'idList' => '['.$thirdid.']',
            'processCode' => $code,
            'remark' => $content,
            'imageFiles' => $images,
        ];
        try{
            $result = $this->service->execute('/mas/atComplaint/alipayComplaint.do', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        $params = [
            'complaintId' => $thirdid,
            'replyContent' => $content,
        ];
        try{
            $result = $this->service->execute('/mas/atComplaint/alipayComplaintReply.do', $params);
            return ['code'=>0, 'data'=>$result];
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