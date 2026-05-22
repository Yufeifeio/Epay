<?php
namespace lib\Complain;

use Exception;

class Wxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];
    private static $operate_type_text = ['USER_CREATE_COMPLAINT'=>'用户提交投诉', 'USER_CONTINUE_COMPLAINT'=>'用户继续投诉', 'USER_RESPONSE'=>'用户留言', 'PLATFORM_RESPONSE'=>'平台留言', 'MERCHANT_RESPONSE'=>'商户留言', 'MERCHANT_CONFIRM_COMPLETE'=>'商户申请结单', 'USER_CREATE_COMPLAINT_SYSTEM_MESSAGE'=>'用户提交投诉系统通知', 'COMPLAINT_FULL_REFUNDED_SYSTEM_MESSAGE'=>'投诉单发起全额退款系统通知', 'USER_CONTINUE_COMPLAINT_SYSTEM_MESSAGE'=>'用户继续投诉系统通知', 'USER_REVOKE_COMPLAINT'=>'用户主动撤诉', 'USER_COMFIRM_COMPLAINT'=>'用户确认投诉解决', 'PLATFORM_HELP_APPLICATION'=>'平台催办', 'USER_APPLY_PLATFORM_HELP'=>'用户申请平台协助', 'MERCHANT_APPROVE_REFUND'=>'商户同意退款申请', 'MERCHANT_REFUSE_RERUND'=>'商户拒绝退款申请', 'USER_SUBMIT_SATISFACTION'=>'用户提交满意度调查结果', 'SERVICE_ORDER_CANCEL'=>'服务订单已取消', 'SERVICE_ORDER_COMPLETE'=>'服务订单已完成'];

    function __construct($channel){
		$this->channel = $channel;
        $wechatpay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
        $this->service = new \WeChatPay\V3\ComplainService($wechatpay_config);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');

        $count_add = 0;
        $count_update = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            try{
                $result = $this->service->batchQuery($begin_date, $end_date, $page_num, $page_size);
            } catch (Exception $e) {
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
            if($result['offset'] == 0 && $result['total_count'] == 0 || count($result['data']) == 0) break;

            foreach($result['data'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条投诉记录，更新'.$count_update.'条投诉记录'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        try{
            $info = $this->service->query($thirdid);
        } catch (Exception $e) {
            return false;
        }
        $retcode = $this->updateInfo($info);

        //发送消息通知
        $need_notice_type = ['CREATE_COMPLAINT', 'CONTINUE_COMPLAINT', 'USER_RESPONSE', 'RESPONSE_BY_PLATFORM'];
        if($retcode>0 && in_array($type, $need_notice_type)){
            if($type == 'CONTINUE_COMPLAINT') $msgtype = '用户继续投诉，请尽快处理';
            else if($type == 'USER_RESPONSE') $msgtype = '用户有新留言，请注意查看';
            else if($type == 'RESPONSE_BY_PLATFORM') $msgtype = '平台有新留言，请注意查看';
            else $msgtype = '您有新的支付交易投诉，请尽快处理';
            
            CommUtil::sendMsg($msgtype, $thirdid);
        }
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        try{
            $info = $this->service->query($data['thirdid']);
            $replys = $this->service->queryHistorys($data['thirdid']);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['complaint_state']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = round($info['complaint_order_info'][0]['amount']/100, 2);
        $data['images'] = [];
        if(!empty($info['complaint_media_list'])){
            foreach($info['complaint_media_list'] as $media){
                foreach($media['media_url'] as $media_url){
                    $data['images'][] = $this->getImageUrl($media_url);
                }
            }
        }
        $data['is_full_refunded'] = $info['complaint_full_refunded']; //订单是否已全额退款
        $data['incoming_user_response'] = $info['incoming_user_response']; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['user_complaint_times']; //用户投诉次数
        if($info['problem_type'] == 'REFUND' && isset($info['apply_refund_amount'])){
            $data['apply_refund_amount'] = round($info['apply_refund_amount']/100, 2); //申请退款金额
        }

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        foreach($replys['data'] as $row){
            $i++;
            if(empty($row['operate_details'])) continue;
            $time = date('Y-m-d H:i:s', strtotime($row['operate_time']));
            $images = [];
            if(!empty($row['complaint_media_list'])){
                foreach($row['complaint_media_list']['media_url'] as $media_url){
                    $images[] = $this->getImageUrl($media_url);
                }
            }
            if($row['operator']=='投诉人' && $i == 1){
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>'发起投诉', 'images'=>[]];
            }else{
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>$row['operate_details'], 'images'=>$images];
            }
        }
        $data['reply_detail_infos'] = array_reverse($data['reply_detail_infos']);

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaint_id'];
        $trade_no = $info['complaint_order_info'][0]['out_trade_no'];
        $api_trade_no = $info['complaint_order_info'][0]['transaction_id'];
        $status = self::getStatus($info['complaint_state']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            if(strlen($trade_no) > 19){
                $sub_order = $DB->find('suborder', 'trade_no', ['sub_trade_no'=>$trade_no]);
                if($sub_order){
                    $trade_no = $sub_order['trade_no'];
                }
            }
            $order = $DB->find('order', 'uid', ['trade_no'=>$trade_no]);
            if(!$order){
                $order = $DB->find('order', 'trade_no,uid', ['api_trade_no'=>$api_trade_no]);
                if($order){
                    $trade_no = $order['trade_no'];
                }else{
                    $order = $DB->find('order', 'trade_no,uid', ['bill_trade_no'=>$api_trade_no]);
                    if($order){
                        $trade_no = $order['trade_no'];
                    }else{
                        $order = $DB->find('order', 'trade_no,uid', ['bill_mch_trade_no'=>$trade_no]);
                        if($order){
                            $trade_no = $order['trade_no'];
                        }else{
                            if(!$conf['complain_range']) return 0;
                        }
                    }
                }
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
                $time = date('Y-m-d H:i:s', strtotime($info['complaint_time']));
                $type = self::$problem_type_text[$info['problem_type']] ?? '其他类型';
                $phone = $info['payer_phone'] ? $this->service->rsaDecrypt($info['payer_phone']) : null;
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'], 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$phone, 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['complainted_mchid'], 'money'=>round($info['complaint_order_info'][0]['amount']/100, 2)]);

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
        try{
            $image_id = $this->service->uploadImage($filepath, $filename);
            return ['code'=>0, 'image_id'=>$image_id];
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
        $mchid = $this->channel['thirdmchid'] ? $this->channel['thirdmchid'] : $this->channel['appmchid'];
        if($images === null) $images = [];
        try{
            $this->service->response($thirdid, $mchid, $content, $images);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        $params = [
            'action' => $code == 1 ? 'APPROVE' : 'REJECT',
        ];
        if($code == 0){
            if($images === null) $images = [];
            $params += [
                'reject_reason' => $content,
                'reject_media_list' => $images,
                'remark' => $remark
            ];
        }else{
            $params += [
                'launch_refund_day' => 0
            ];
        }
        try{
            $this->service->updateRefundProgress($thirdid, $params);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $mchid = $this->channel['thirdmchid'] ? $this->channel['thirdmchid'] : $this->channel['appmchid'];
        try{
            $this->service->complete($thirdid, $mchid);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //设置投诉通知回调地址
    public function setNotifyUrl(){
        global $conf;
        $notifyUrl = $conf['localurl'].'pay/complainnotify/'.$this->channel['id'].'/';
        try{
            $result = $this->service->queryNotifications();
            if($result['url'] == $notifyUrl) return ['code'=>0];

            $this->service->updateNotifications($notifyUrl);

            return ['code'=>0];
        } catch (\WeChatPay\V3\WeChatPayException $e) {
            if($e->getResponse()['code'] != 'RESOURCE_NOT_EXISTS'){
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        try{
            $this->service->createNotifications($notifyUrl);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //删除投诉通知回调地址
    public function delNotifyUrl(){
        try{
            $this->service->queryNotifications();

            $this->service->deleteNotifications();

            return ['code'=>0];
        } catch (\WeChatPay\V3\WeChatPayException $e) {
            if($e->getResponse()['code'] == 'RESOURCE_NOT_EXISTS'){
                return ['code'=>0];
            }
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }
    
    //下载图片（仅微信）
    public function getImage($media_id){
        try{
            $image = $this->service->getImage($media_id);
            return $image;
        }catch (Exception $e) {
            //echo $e->getMessage();
        }
        return true;
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
}