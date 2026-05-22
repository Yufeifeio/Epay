<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'huifu/inc/HuifuClient.php';

class HuifuWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new HuifuComplainService($channel);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num < 250 ? 250 : $num;
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
            if($result['offset'] == 0 && $result['total_count'] == 0 || empty($result['complaint_list'])) break;
            $result['complaint_list'] = json_decode($result['complaint_list'], true);
            if(count($result['complaint_list']) == 0) break;

            foreach($result['complaint_list'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;

                if(isset($_GET['key']) && self::getStatus($info['status']) < 2){ //监控模式
                    global $DB;
                    $msgtype = null;
                    if($rescode == 2){
                        $msgtype = '用户提交了新的反馈，请尽快处理';
                    }elseif($rescode == 1){
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
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        try{
            $info = $this->service->query($data['thirdid']);
            $replys = $this->service->queryHistorys($data['thirdid'], self::getMchId()[0]);
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

        $data['money'] = round($info['amount']/100, 2);
        $data['images'] = [];
        if(!empty($info['complaint_media_list'])){
            foreach($info['complaint_media_list'] as $media){
                foreach($media['media_url'] as $media_url){
                    $data['images'][] = $this->getImageUrl($media_url, $data['thirdid']);
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
        foreach($replys as $row){
            $i++;
            if(empty($row['operate_details'])) continue;
            $time = date('Y-m-d H:i:s', strtotime($row['operate_time']));
            $images = [];
            if(!empty($row['complaint_media_list'])){
                foreach($row['complaint_media_list']['media_url'] as $media_url){
                    $images[] = $this->getImageUrl($media_url, $data['thirdid']);
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
        $api_trade_no = $info['out_trade_no'];
        $bill_trade_no = $info['transaction_id'];
        $status = self::getStatus($info['complaint_state']);
        
        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid', ['api_trade_no'=>$api_trade_no]);
            if($order){
                $trade_no = $order['trade_no'];
            }else{
                $order = $DB->find('order', 'trade_no,uid', ['bill_trade_no'=>$bill_trade_no]);
                if($order){
                    $trade_no = $order['trade_no'];
                }else{
                    $trade_no = $api_trade_no;
                    if(!$conf['complain_range']) return 0;
                }
            }
        }else{
            $trade_no = $row['trade_no'];
        }

        if($row){
            if($status != $row['status']){
                $DB->update('complain', ['status'=>$status, 'edittime'=>'NOW()'], ['id'=>$row['id']]);
                if($row['status'] == 2 && $status == 1 && $conf['complain_auto_reply'] >= 1 && !empty($conf['complain_auto_reply_con']) && $conf['complain_auto_reply_repeat']==1){
                    usleep(300000);
                    $this->channel['thirdmchid'] = $info['mchid'].'|'.$info['complainted_mchid'];
                    $this->feedbackSubmit($thirdid, '', $conf['complain_auto_reply_con']);
                }
                CommUtil::autoHandle($trade_no, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $time = date('Y-m-d H:i:s', strtotime($info['complaint_time']));
                $type = self::$problem_type_text[$info['problem_type']] ?? '其他类型';
                $phone = $info['payer_phone'];
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'], 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$phone, 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['mchid'].'|'.$info['complainted_mchid'], 'money'=>round($info['amount']/100, 2)]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->channel['thirdmchid'] = $info['mchid'].'|'.$info['complainted_mchid'];
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
        $mchids = self::getMchId();
        if($images === null) $images = [];
        try{
            $this->service->response($thirdid, $mchids[0], $mchids[1], $content, $images);
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
            $params['reject_reason'] = $content;
            $params['remark'] = $remark;
            if(!empty($images)){
                foreach($images as $key=>$image){
                    $file_info['reject_media_pic'.($key+1)] = $image;
                }
                $params['file_info'] = $file_info;
            }
        }else{
            $params['launch_refund_day'] = 0;
        }
        try{
            $this->service->updateRefundProgress($thirdid, self::getMchId()[0], $params);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $mchids = self::getMchId();
        try{
            $this->service->complete($thirdid, $mchids[0], $mchids[1]);
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
        try{
            $image = $this->service->getImage($media_id, $_GET['thirdid']);
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

    private function getImageUrl($url, $thirdid){
        $media_id = substr($url, strpos($url, '/images/')+8);
        return './download.php?act=wximg&channel='.$this->channel['id'].'&mediaid='.$media_id.'&thirdid='.$thirdid;
    }

    private function getMchId(){
        return explode('|',$this->channel['thirdmchid']);
    }
}


class HuifuComplainService
{
    private $client;

    function __construct($channel){
        $config_info = [
			'sys_id' =>  $channel['appid'],
			'product_id' => $channel['appurl'],
			'merchant_private_key' => $channel['appsecret'],
			'huifu_public_key' => $channel['appkey'],
		];
		$this->client = new \HuifuClient($config_info);
	}

    //查询投诉单列表
    public function batchQuery($begin_date, $end_date, $page_no = 1, $page_size = 10){
        $path = '/v2/merchant/complaint/list/info/query';
        $offset = $page_size * ($page_no-1);
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'begin_date' => $begin_date,
            'end_date' => $end_date,
            'offset' => $offset,
            'limit' => $page_size
        ];
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return $result;
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //查询投诉单详情
    public function query($complaint_id)
    {
        $path = '/v2/merchant/complaint/list/info/query';
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'begin_date' => $begin_date,
            'end_date' => $end_date,
            'complaint_id' => $complaint_id
        ];
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            if($result['total_count'] == 0 || empty($result['complaint_list']))throw new Exception('微信投诉单不存在');
            $result['complaint_list'] = json_decode($result['complaint_list'], true);
            return $result['complaint_list'][0];
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //查询投诉协商历史
    public function queryHistorys($complaint_id, $mch_id)
    {
        $path = '/v2/merchant/complaint/history/query';
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'complaint_id' => $complaint_id,
            'offset' => 0,
            'limit' => 50,
            'mch_id' => $mch_id
        ];
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return json_decode($result['complaint_history_list'], true);
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //回复用户
    public function response($complaint_id, $mch_id, $complainted_mchid, $response_content, $response_images)
    {
        $path = '/v2/merchant/complaint/reply';
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'complaint_id' => $complaint_id,
            'complainted_mchid' => $complainted_mchid,
            'response_content' => $response_content,
            'mch_id' => $mch_id
        ];
        if(!empty($response_images)){
            foreach($response_images as $key=>$image){
                $file_info['response_pic'.($key+1)] = $image;
            }
            $params['file_info'] = $file_info;
        }
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return true;
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //反馈处理完成
    public function complete($complaint_id, $mch_id, $complainted_mchid)
    {
        $path = '/v2/merchant/complaint/complete';
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'complaint_id' => $complaint_id,
            'complainted_mchid' => $complainted_mchid,
            'mch_id' => $mch_id
        ];
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return true;
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //更新退款审批结果
    public function updateRefundProgress($complaint_id, $mch_id, $params)
    {
        $path = '/v2/merchant/complaint/update/refundprogress';
        $addparams = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'complaint_id' => $complaint_id,
            'mch_id' => $mch_id
        ];
        $params = array_merge($addparams, $params);
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return true;
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //上传反馈图片
    public function uploadImage($file_path, $file_name)
    {
        $path = '/v2/supplementary/picture';
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'file_type' => 'F246',
        ];
        $result = $this->client->upload($path, $params, $file_path, $file_name);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return $result['file_id'];
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }

    //下载图片
    public function getImage($media_id, $complaint_id)
    {
        $media_url = 'https://api.mch.weixin.qq.com/v3/merchant-service/images/'.urlencode($media_id);
        $path = '/v2/merchant/complaint/download/picture';
        $params = [
            'req_seq_id' => date("YmdHis").rand(11111,99999),
            'req_date' => date("Ymd"),
            'media_url' => $media_url,
            'complaint_id' => $complaint_id,
        ];
        $result = $this->client->requestApi($path, $params);
        if(isset($result['resp_code']) && $result['resp_code']=='00000000') {
            return base64_decode($result['media_data']);
        }elseif(isset($result['resp_desc'])){
            throw new Exception($result['resp_desc']);
        }else{
            throw new Exception('返回数据解析失败');
        }
    }
}