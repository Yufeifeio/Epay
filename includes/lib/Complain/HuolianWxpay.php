<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'huolian/inc/HuolianClient.class.php';

class HuolianWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = [101=>'投诉商家', 102=>'申请退款', 103=>'服务未生效'];
    private static $user_type_text = ['user'=>'投诉人', 'merchat'=>'商家', 'system'=>'系统'];
    private static $operator_type_text = ['USER_CREATE_COMPLAINT'=>'用户提交投诉', 'USER_CONTINUE_COMPLAINT'=>'用户继续投诉', 'USER_RESPONSE'=>'用户留言', 'PLATFORM_RESPONSE'=>'平台留言', 'MERCHANT_RESPONSE'=>'商户留言', 'MERCHANT_CONFIRM_COMPLETE'=>'商户申请结单', 'USER_CREATE_COMPLAINT_SYSTEM_MESSAGE'=>'用户提交投诉系统通知', 'COMPLAINT_FULL_REFUNDED_SYSTEM_MESSAGE'=>'投诉单发起全额退款系统通知', 'USER_CONTINUE_COMPLAINT_SYSTEM_MESSAGE'=>'用户继续投诉系统通知', 'USER_REVOKE_COMPLAINT'=>'用户主动撤诉', 'USER_COMFIRM_COMPLAINT'=>'用户确认投诉解决', 'PLATFORM_HELP_APPLICATION'=>'平台催办', 'USER_APPLY_PLATFORM_HELP'=>'用户申请平台协助', 'MERCHANT_APPROVE_REFUND'=>'商户同意退款申请', 'MERCHANT_REFUSE_RERUND'=>'商户拒绝退款申请', 'USER_SUBMIT_SATISFACTION'=>'用户提交满意度调查结果', 'SERVICE_ORDER_CANCEL'=>'服务订单已取消', 'SERVICE_ORDER_COMPLETE'=>'服务订单已完成'];

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new HuolianComplainService($channel);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);
        $begin_date = date('Y-m-d', strtotime('-3 days'));
        $end_date = date('Y-m-d');

        $count_add = 0;
        $count_update = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            try{
                $result = $this->service->batchQuery($begin_date, $end_date, $page_num, $page_size);
            } catch (Exception $e) {
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
            if($result['pageNo'] == 1 && $result['totalCount'] == 0 || empty($result['complaintList'])) break;

            foreach($result['complaintList'] as $info){
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
        $need_notice_type = ['USER_CREATE_COMPLAINT', 'USER_CREATE_COMPLAINT_SYSTEM_MESSAGE', 'USER_CONTINUE_COMPLAINT', 'USER_RESPONSE', 'PLATFORM_RESPONSE'];
        if($retcode==1 || in_array($type, $need_notice_type)){
            if($type == 'USER_CONTINUE_COMPLAINT') $msgtype = '用户继续投诉，请尽快处理';
            else if($type == 'USER_RESPONSE') $msgtype = '用户有新留言，请注意查看';
            else if($type == 'PLATFORM_RESPONSE') $msgtype = '平台有新留言，请注意查看';
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
        if($info === null){
            return ['code'=>-1, 'msg'=>'投诉单不存在'];
        }

        $status = self::getStatus($info['complaintStatus']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = $info['orderAmount'];
        $data['images'] = [];
        $data['incoming_user_response'] = $info['needReply']; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['complaintCount']; //用户投诉次数

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        foreach($replys as $row){
            $i++;
            if(empty($row['replyContent'])) continue;
            $time = $row['operateTime'];
            $images = [];
            if(!empty($row['replyImage'])){
                $replyImage = json_decode($row['replyImage'], true);
                foreach($replyImage as $media){
                    $images[] = $media;
                }
            }
            $type = self::getUserType($row['operateType']);
            $typename = self::$user_type_text[$type];
            if(empty($row['replyContent'])) $row['replyContent'] = self::$operator_type_text[$row['operateType']] ?? '';
            $data['reply_detail_infos'][] = ['type'=>$type, 'name'=>$typename, 'time'=>$time, 'content'=>$row['replyContent'], 'images'=>$images];
        }
        $data['reply_detail_infos'] = $data['reply_detail_infos'];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['huolianComplaintNo'];
        $trade_no = $info['businessOrderNo'];
        $status = self::getStatus($info['complaintStatus']);
        
        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['trade_no'=>$trade_no]);
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
                $time = $info['complaintTime'];
                $type = self::$problem_type_text[$info['complaintType']] ?? '其他类型';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$order['type'], 'channel'=>$order['channel'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>'微信交易投诉('.$info['complaintCount'].'次)', 'content'=>$info['content'], 'status'=>$status, 'phone'=>$info['payerPhone'], 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['channelMerchantNo'], 'money'=>$info['orderAmount']]);

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
        if($images === null) $images = [];
        try{
            $this->service->response($thirdid, $content, $images);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        $params = [
            'actionType' => $code == 1 ? 'approve' : 'reject',
        ];
        if($code == 0){
            if($images === null) $images = [];
            $params += [
                'replyContent' => $content,
                'replyImage' => json_encode($images)
            ];
        }
        try{
            $this->service->refundProgressSubmit($thirdid, $params);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        try{
            $this->service->complete($thirdid);
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
            if($result == $notifyUrl) return ['code'=>0];

            $this->service->setNotifications($notifyUrl);

            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //删除投诉通知回调地址
    public function delNotifyUrl(){
        try{
            $result = $this->service->queryNotifications();
            if(empty($result)) return ['code'=>0];

            $this->service->setNotifications('');

            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        return false;
    }

    private static function getStatus($status){
        if($status == 0){
            return 0;
        }elseif($status == 2){
            return 1;
        }else{
            return 2;
        }
    }

    private static function getUserType($type){
        $user_types = ['USER_CREATE_COMPLAINT', 'USER_CONTINUE_COMPLAINT', 'USER_RESPONSE', 'USER_REVOKE_COMPLAINT', 'USER_COMFIRM_COMPLAINT', 'USER_APPLY_PLATFORM_HELP', 'USER_SUBMIT_SATISFACTION'];
        $merchat_types = ['MERCHANT_RESPONSE', 'MERCHANT_CONFIRM_COMPLETE', 'MERCHANT_APPROVE_REFUND', 'MERCHANT_REFUSE_RERUND'];
        if(in_array($type, $user_types)){
            return 'user';
        }elseif(in_array($type, $merchat_types)){
            return 'merchat';
        }else{
            return 'system';
        }
    }

}


class HuolianComplainService
{
    private $client;
    private $channel;

    function __construct($channel){
        $this->channel = $channel;
		$this->client = new \HuolianClient($channel['appid'], $channel['appkey']);
	}

    //查询投诉单列表
    public function batchQuery($begin_date, $end_date, $page_no = 1, $page_size = 10){
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'pageNo' => $page_no,
            'pageSize' => $page_size,
            'beginTime' => $begin_date.' 00:00:00',
            'endTime' => $end_date.' 23:59:59',
        ];
        $result = $this->client->execute('api.hl.complaint.list.query', $params);
        return $result;
    }

    //查询投诉单详情
    public function query($complaint_id)
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'huolianComplaintNo' => $complaint_id,
        ];
        $result = $this->client->execute('api.hl.complaint.detail.query', $params);
        return $result;
    }

    //查询投诉协商历史
    public function queryHistorys($complaint_id)
    {
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d', strtotime('+1 days'));
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'huolianComplaintNo' => $complaint_id,
            'pageNo' => 1,
            'pageSize' => 20,
            'beginTime' => $begin_date.' 00:00:00',
            'endTime' => $end_date.' 00:00:00',
        ];
        $result = $this->client->execute('api.hl.complaint.log.list.query', $params);
        return $result['communicationLogList'];
    }

    //回复用户
    public function response($complaint_id, $response_content, $response_images)
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'huolianComplaintNo' => $complaint_id,
            'replyContent' => $response_content,
            'operatorUserAccount' => $this->channel['appurl'],
        ];
        if(!empty($response_images)){
            $params['replyImage'] = json_encode($response_images);
        }
        $this->client->execute('api.hl.complaint.reply', $params);
        return true;
    }

    //反馈处理完成
    public function complete($complaint_id)
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'huolianComplaintNo' => $complaint_id,
            'operatorUserAccount' => $this->channel['appurl'],
        ];
        $this->client->execute('api.hl.complaint.complete', $params);
        return true;
    }

    //更新退款审批结果
    public function refundProgressSubmit($complaint_id, $params)
    {
        $public_params = [
            'merchantNo' => $this->channel['appmchid'],
            'huolianComplaintNo' => $complaint_id,
            'operatorUserAccount' => $this->channel['appurl'],
        ];
        $params = array_merge($public_params, $params);
        $this->client->execute('api.hl.complaint.complete', $params);
        return true;
    }

    //上传反馈图片
    public function uploadImage($file_path, $file_name)
    {
        $result = $this->client->upload('api.hl.complaint.upload.image', $file_path, $file_name);
        return $result['imgUrl'];
    }

    //查询投诉通知回调地址
    public function queryNotifications()
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'effectScope' => 'CALLBACK_FOR_MERCHANT'
        ];
        $result = $this->client->execute('api.hl.complaint.notify.query', $params);
        return $result['notifyUrl'];
    }

    //设置投诉通知回调地址
    public function setNotifications($notifyUrl)
    {
        $params = [
            'merchantNo' => $this->channel['appmchid'],
            'notifyUrl' => $notifyUrl,
            'effectScope' => 'CALLBACK_FOR_MERCHANT'
        ];
        $this->client->execute('api.hl.complaint.notify.set', $params);
    }
}