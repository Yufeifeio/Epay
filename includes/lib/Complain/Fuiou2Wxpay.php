<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'fuiou2/inc/EntryService.class.php';

class Fuiou2Wxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \EntryService($channel['appid'],$channel['entrykey'],$channel['appswitch']==1);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);
        $begin_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');

        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'begin_date' => $begin_date,
            'end_date' => $end_date,
            'limit' => $limit,
            'offset' => 0,
        ];
        try{
            $result = $this->service->submit('/wxcomplaint.fuiou?action=queryComplaintList', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['datas']['data'])){
            if(isset($result['datas']['data']['out_trade_no'])){
                $result['datas']['data'] = [$result['datas']['data']];
            }
            foreach($result['datas']['data'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条投诉记录，更新'.$count_update.'条投诉记录'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'complaint_id' => $thirdid,
        ];
        try{
            $info = $this->service->submit('/wxcomplaint.fuiou?action=queryComplaint', $params);
        } catch (Exception $e) {
            return false;
        }
        $info['out_trade_no'] = $info['complaint_order_infos']['complaint_order_info']['out_trade_no'];
        $info['transaction_id'] = $info['complaint_order_infos']['complaint_order_info']['transaction_id'];
        $retcode = $this->updateInfo($info);

        //发送消息通知
        $need_notice_type = ['CREATE_COMPLAINT', 'CONTINUE_COMPLAINT', 'USER_RESPONSE', 'RESPONSE_BY_PLATFORM'];
        if($retcode==1 || in_array($type, $need_notice_type)){
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
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'complaint_id' => $data['thirdid']
        ];
        try{
            $info = $this->service->submit('/wxcomplaint.fuiou?action=queryComplaint', $params);
            $replys = $this->service->submit('/wxcomplaint.fuiou?action=queryConsultHistory', $params);
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
                if(is_array($media['media_url'])){
                    foreach($media['media_url'] as $media_url){
                        $data['images'][] = $this->getImageUrl($media_url);
                    }
                }else{
                    $data['images'][] = $this->getImageUrl($media['media_url']);
                }
            }
        }
        $data['is_full_refunded'] = $info['full_refunded']; //订单是否已全额退款
        $data['incoming_user_response'] = $info['has_user_response']; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['user_complaint_times']; //用户投诉次数
        if($info['problem_type'] == 'REFUND' && isset($info['apply_refund_amount'])){
            $data['apply_refund_amount'] = round($info['apply_refund_amount']/100, 2); //申请退款金额
        }

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        if(!empty($replys['datas']['data'])){
            if(isset($result['datas']['data']['operate_time'])){
                $result['datas']['data'] = [$result['datas']['data']];
            }
            foreach($replys['datas']['data'] as $row){
                $i++;
                if(empty($row['operate_details'])) continue;
                $time = $row['operate_time'];
                $images = [];
                if(!empty($row['complaint_media_list'])){
                    if(is_array($row['complaint_media_list']['media_url'])){
                        foreach($row['complaint_media_list']['media_url'] as $media_url){
                            $images[] = $this->getImageUrl($media_url);
                        }
                    }else{
                        $images[] = $this->getImageUrl($row['complaint_media_list']['media_url']);
                    }
                }
                if($row['operator']=='投诉人' && $i == 1){
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>'发起投诉', 'images'=>[]];
                }else{
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>$row['operate_details'], 'images'=>$images];
                }
            }
            $data['reply_detail_infos'] = array_reverse($data['reply_detail_infos']);
        }

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaint_id'];
        $trade_no = $info['out_trade_no'];
        $api_trade_no = $info['transaction_id'];
        $status = self::getStatus($info['complaint_state']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['api_trade_no'=>$trade_no]);
            if($order){
                $trade_no = $order['trade_no'];
            }else{
                if(!$conf['complain_range']) return 0;
                $trade_no = $api_trade_no;
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
                if(!isset($info['problem_type']) || !isset($info['problem_description'])){
                    $params = [
                        'mchnt_cd' => $this->channel['appmchid'],
                        'complaint_id' => $thirdid,
                    ];
                    try{
                        $info = $this->service->submit('/wxcomplaint.fuiou?action=queryComplaint', $params);
                    } catch (Exception $e) {
                        return 0;
                    }
                }
                $type = self::$problem_type_text[$info['problem_type']] ?? '其他类型';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'], 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$info['payer_phone'], 'addtime'=>$info['complaint_time'], 'edittime'=>$info['complaint_time'], 'money'=>round($info['complaint_order_info'][0]['amount']/100, 2)]);

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
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'file' => new \CURLFile($filepath, self::mime_content_type($ext), $filename),
        ];
        try{
            $result = $this->service->submit('/wxcomplaint.fuiou?action=uploadImage', $params, true);
            return ['code'=>0, 'image_id'=>$result['media_id']];
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
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'complaint_id' => $thirdid,
            'reply_content' => $content,
            'images' => implode(',', $images)
        ];
        try{
            $result = $this->service->submit('/wxcomplaint.fuiou?action=replyToUser', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'complaint_id' => $thirdid,
            'action' => $code == 1 ? 'APPROVE' : 'REJECT',
        ];
        if($code == 0){
            if($images === null) $images = [];
            $params += [
                'reject_reason' => $content,
                'reject_media_list' => implode(',', $images),
                'remark' => $remark
            ];
        }else{
            $params += [
                'refund_day' => 0
            ];
        }
        try{
            $result = $this->service->submit('/wxcomplaint.fuiou?action=updateRefundSts', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'complaint_id' => $thirdid,
        ];
        try{
            $result = $this->service->submit('/wxcomplaint.fuiou?action=handleCompleted', $params);
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
        $url = 'https://api.mch.weixin.qq.com/v3/merchant-service/images/'.urlencode($media_id);
        $params = [
            'mchnt_cd' => $this->channel['appmchid'],
            'media_url' => $url
        ];
        try{
            $image = $this->service->submit('/wxcomplaint.fuiou?action=downloadImage', $params, false, true);
            return $image;
        } catch (Exception $e) {
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
        return './download.php?act=wximg&channel='.$this->channel['id'].'&subchannel='.$this->channel['subid'].'&mediaid='.$media_id;
    }
}