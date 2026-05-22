<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'shengpay/inc/ShengPayClient.php';

class ShengpayWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

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
            $replys = $this->service->execute('/order/complaint/complaintHistory', $params);
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
        $mediaList = json_decode($info['mediaList'], true);
        if(!empty($mediaList)){
            foreach($mediaList as $media){
                foreach($media['media_url'] as $media_url){
                    $data['images'][] = $this->getImageUrl($media_url);
                }
            }
        }
        $data['is_full_refunded'] = $info['fullRefunded'] == 'true'; //订单是否已全额退款
        $data['incoming_user_response'] = $info['userResponse'] == 'true'; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['complaintTimes']; //用户投诉次数
        if($info['problemType'] == 'REFUND' && isset($info['applyRefundAmount'])){
            $data['apply_refund_amount'] = round($info['applyRefundAmount']/100, 2); //申请退款金额
        }

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        if(!empty($replys['detail_list'])){
            foreach($replys['detail_list'] as $row){
                $i++;
                if(empty($row['operate_details'])) continue;
                $time = $row['operate_time'];
                $images = json_decode($row['image_list'], true);
                if($row['operator']=='投诉人' && $i == 1){
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>'发起投诉', 'images'=>[]];
                }else{
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>$row['operate_details'], 'images'=>$images];
                }
            }
        }

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
                if($row['status'] == 2 && $status == 1 && $conf['complain_auto_reply'] >= 1 && !empty($conf['complain_auto_reply_con']) && $conf['complain_auto_reply_repeat']==1){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, '', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $type = self::$problem_type_text[$info['problemType']] ?? '交易投诉';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problemDescription'] ?? '-', 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['payerPhone'], 'addtime'=>$info['complaintTime'], 'edittime'=>$info['complaintTime'], 'thirdmchid'=>$info['instMchId'], 'money'=>round($info['amount']/100, 2)]);

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
        global $conf;
        $result = $this->replySubmit($thirdid, $content, $images);
        if($result['code'] == 0 && $conf['complain_auto_reply'] == 1){
            return $this->complete($thirdid);
        }
        return $result;
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
        $params = [
            'subMchId' => $this->channel['appmchid'],
            'complaintId' => $thirdid,
            'action' => $code == 1 ? 'APPROVE' : 'REJECT',
        ];
        if($code == 0){
            if($images === null) $images = [];
            $params += [
                'rejectReason' => $content,
                'rejectMediaList' => json_encode($images),
                'remark' => $remark
            ];
        }else{
            $params += [
                'launchRefundDay' => 0
            ];
        }
        try{
            $result = $this->service->execute('/order/complaint/updateRefundProgress', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $params = [
            'subMchId' => $this->channel['appmchid'],
            'complaintId' => $thirdid,
        ];
        try{
            $result = $this->service->execute('/order/complaint/completeComplaint', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        $params = [
            'mediaUrl' => 'https://api.mch.weixin.qq.com/v3/merchant-service/images/'.$media_id,
            'subMchId' => $this->channel['appmchid'],
        ];
        try{
            $result = $this->service->execute('/order/complaint/imageDownContent', $params);
            return base64_decode($result['imageResponse']);
        } catch (Exception $e) {
        }
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

    private static function getUserType($type){
        if($type == '投诉人'){
            return 'user';
        }elseif($type == '商家'){
            return 'merchat';
        }else{
            return 'system';
        }
    }

    private function getImageUrl($url){
        $media_id = substr($url, strpos($url, '/images/')+8);
        return './download.php?act=wximg&channel='.$this->channel['id'].'&mediaid='.$media_id;
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