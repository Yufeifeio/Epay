<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'shengpay/inc/ShengPayClient.php';

class ShengpayAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \ShengPayClient($channel['appid'],$channel['appkey'],$channel['appsecret']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        return ['code'=>-1, 'msg'=>'盛付通不支持拉取投诉列表，只能通过回调更新'];
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
            'subMchId' => $this->channel['appmchid'],
        ];
        try{
            $info = $this->service->execute('/order/complaint/detail', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['complaintState']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = round($info['amount']/100, 2);
        $data['images'] = [];
        $data['is_full_refunded'] = $info['fullRefunded'] == 'true'; //订单是否已全额退款
        $data['incoming_user_response'] = $info['userResponse'] == 'true'; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['complaintTimes']; //用户投诉次数
        if($info['problemType'] == 'REFUND' && isset($info['applyRefundAmount'])){
            $data['apply_refund_amount'] = round($info['applyRefundAmount']/100, 2); //申请退款金额
        }

        $data['reply_detail_infos'] = []; //协商记录

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintId'];
        $trade_no = $info['outTradeNo'];
        $api_trade_no = $info['sftInstOrderNo'];
        $status = self::getStatus($info['complaintState']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['trade_no'=>$trade_no]);
            if(!$order){
                if(!$conf['complain_range']) return 0;
            }
        }

        if($row){
            if($status != $row['status']){
                $DB->update('complain', ['status'=>$status, 'edittime'=>'NOW()'], ['id'=>$row['id']]);
                CommUtil::autoHandle($trade_no, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $type = self::$problem_type_text[$info['problemType']] ?? '交易投诉';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problemDescription'] ?? '-', 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['payerPhone'], 'addtime'=>$info['complaintTime'], 'edittime'=>$info['complaintTime'], 'thirdmchid'=>$info['instMchId'], 'money'=>round($info['amount']/100, 2)]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
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
            'fileName' => $filename,
            'sha256' => hash_file('sha256', $filepath),
        ];
        try{
            $result = $this->service->upload('/pmtmch/media/upload', $params, $filepath, $filename);
            return ['code'=>0, 'image_id'=>$result['mediaId']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = '04';
        $code_list = ['CONSENSUS_WITH_CLIENT'=>'03','RECTIFICATION_NO_REFUND'=>'05','REFUND'=>'00','SUBMIT_PROOF_NOT_CONTACTED'=>'06','OTHER_CHANNEL_REFUND'=>'02','ORTHER'=>'04'];
        $params = [
            'subMchId' => $this->channel['appmchid'],
            'complaintId' => $thirdid,
            'feedbackCode' => $code_list[$code],
            'feedbackContent' => $content,
            'feedbackImages' => !empty($images) ? implode(',', $images) : '',
            'operator' => '000001',
        ];
        try{
            $result = $this->service->execute('/order/complaint/fillSupplement', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        $params = [
            'subMchId' => $this->channel['appmchid'],
            'complaintId' => $thirdid,
            'responseContent' => $content,
        ];
        if(!empty($images)) $params['responseImages'] = json_encode($images);
        try{
            $result = $this->service->execute('/order/complaint/submitResponse', $params);
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
        if($status == 'PENDING'){
            return 0;
        }elseif($status == 'PROCESSING'){
            return 1;
        }else{
            return 2;
        }
    }

    //设置投诉通知回调地址
    public function setNotifyUrl(){
        global $conf;
        $notifyUrl = $conf['localurl'].'pay/complainnotify/'.$this->channel['id'].'/';
        $params = [
            'eventGroup' => 'COMPLAINT',
            'url' => $notifyUrl,
        ];
        try{
            $this->service->execute('/merchant/event/subscribe', $params);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //删除投诉通知回调地址
    public function delNotifyUrl(){
        $params = [
            'eventGroup' => 'COMPLAINT',
        ];
        try{
            $this->service->execute('/merchant/event/unsubscribe', $params);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }
}