<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'yeepay/inc/YopClient.php';

class YeepayWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new YeepayComplainService($channel['appkey'], $channel['appsecret']);
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
            $result['list'] = json_decode($result['list'], true);
            if($page_num == 1 && $result['count'] == 0 || empty($result['list'])) break;

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
        try{
            $info = $this->service->query($thirdid);
        } catch (Exception $e) {
            return false;
        }
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
        try{
            $info = $this->service->query($data['thirdid']);
            $replys = $this->service->queryHistorys($data['thirdid']);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        if($info === null){
            return ['code'=>-1, 'msg'=>'投诉单不存在'];
        }

        $status = self::getStatus($info['complaintState']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = $info['amount'];
        $data['images'] = [];
        if(!empty($info['complaintMediaDTOList'])){
            $info['complaintMediaDTOList'] = json_decode($info['complaintMediaDTOList'], true);
            foreach($info['complaintMediaDTOList'] as $media){
                foreach($media['mediaUrlList'] as $media_url){
                    $data['images'][] = $this->getImageUrl($data['thirdid'], $media_url);
                }
            }
        }
        $data['is_full_refunded'] = $info['isFullRefunded']; //订单是否已全额退款
        $data['incoming_user_response'] = $info['isResponse']; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['complaintCount']; //用户投诉次数

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        foreach($replys as $row){
            $i++;
            if(empty($row['operateDetails'])) continue;
            $time = strtotime($row['operateTime']);
            $images = [];
            if(!empty($row['complaintMediaDTO'])){
                $row['complaintMediaDTO'] = json_decode($row['complaintMediaDTO'], true);
                foreach($row['complaintMediaDTO']['mediaUrlList'] as $media_url){
                    $images[] = $this->getImageUrl($data['thirdid'], $media_url);
                }
            }
            if($row['operator']=='投诉人' && $i == 1){
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>'发起投诉', 'images'=>[]];
            }else{
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>$row['operateDetails'], 'images'=>$images];
            }
        }
        $data['reply_detail_infos'] = $data['reply_detail_infos'];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintNo'];
        $trade_no = $info['merchantOrderNo'];
        $status = self::getStatus($info['complaintState']);
        
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
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$order['type'], 'channel'=>$order['channel'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'其他类型', 'title'=>'微信交易投诉('.$info['complaintCount'].'次)', 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['complaintContact'], 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['channelMerchantNo'], 'money'=>$info['amount']]);

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
        return false;
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

    private static function getUserType($type){
        if($type == '投诉人'){
            return 'user';
        }elseif($type == '商家'){
            return 'merchat';
        }else{
            return 'system';
        }
    }

    private function getImageUrl($complaint_id, $url){
        $file_name = md5($url);
        $file_path = 'assets/img/complain/'.$file_name.'.jpg';
        if(file_exists(ROOT.$file_path)){
            return '/'.$file_path;
        }
        $picture = $this->service->downloadImage($complaint_id, $url);
        if(!file_exists(ROOT.'assets/img/complain')){
            mkdir(ROOT.'assets/img/complain');
        }
        file_put_contents(ROOT.$file_path, $picture);
        return '/'.$file_path;
    }
}


class YeepayComplainService extends \Yeepay\YopClient
{
    public function __construct($appKey, $secretKey)
    {
        parent::__construct($appKey, $secretKey);
    }

    //查询投诉单列表
    public function batchQuery($begin_date, $end_date, $page_no = 1, $page_size = 10){
        $params = [
            'complaintSource' => 'WECHAT_BILL',
            'startTime' => $begin_date,
            'endTime' => $end_date,
            'page' => $page_no,
            'limit' => $page_size,
        ];
        $result = $this->get('/rest/v1.0/aggpay/complaint/wechat/list', $params);
        if($result['code'] == '00000'){
            return $result;
        }else{
            throw new Exception($result['message']);
        }
    }

    //查询投诉单详情
    public function query($complaint_id)
    {
        $params = [
            'complaintNo' => $complaint_id,
        ];
        $result = $this->get('/rest/v1.0/aggpay/complaint/wechat/details', $params);
        if($result['code'] == '00000'){
            return $result;
        }else{
            throw new Exception($result['message']);
        }
    }

    //查询投诉协商历史
    public function queryHistorys($complaint_id)
    {
        $params = [
            'complaintNo' => $complaint_id,
        ];
        $result = $this->get('/rest/v1.0/aggpay/complaint/wechat/negotiation-history', $params);
        if($result['code'] == '00000'){
            return $result['list'];
        }else{
            throw new Exception($result['message']);
        }
    }

    //回复用户
    public function response($complaint_id, $response_content, $response_images)
    {
        $params = [
            'complaintNo' => $complaint_id,
            'feedbackContent' => $response_content,
        ];
        if(!empty($response_images)){
            $params['imageList'] = json_encode($response_images);
        }
        $params['feedbackType'] = 'GENERAL';
        $params['complaintSource'] = 'WECHAT_BILL';
        $result = $this->post('/rest/v1.0/aggpay/complaint/wechat/feedback', $params);
        if($result['code'] == '00000'){
            return true;
        }else{
            throw new Exception($result['message']);
        }
    }

    //反馈处理完成
    public function complete($complaint_id)
    {
        $params = [
            'complaintNo' => $complaint_id,
        ];
        $result = $this->post('/rest/v1.0/aggpay/complaint/wechat/success', $params);
        if($result['code'] == '00000'){
            return true;
        }else{
            throw new Exception($result['message']);
        }
    }

    //上传反馈图片
    public function uploadImage($file_path, $file_name)
    {
        $params = [
            'merQual' => new \CURLFile($file_path, null, $file_name),
        ];
        $result = $this->upload('/yos/v1.0/sys/merchant/qual/upload', $params);
        return $result['merQualUrl'];
    }

    //下载图片
    public function downloadImage($complaint_id, $url)
    {
        $params = [
            'complaintNo' => $complaint_id,
            'fileType' => 'IMAGE',
            'pictureUrl' => $url
        ];
        $result = $this->get('/yos/v1.0/aggpay/complaint/wechat/download-pic', $params);
        if($result['code'] == '00000'){
            return $result['picture'];
        }else{
            throw new Exception($result['message']);
        }
    }
}