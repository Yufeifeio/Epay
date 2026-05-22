<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'hnapay/inc/HnaPayApi.class.php';

class HnapayWxpay implements IComplain
{

    static $paytype = 'wxpay';
    const CHANNEL_ID = '777565054'; //微信服务商商户号

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new HnapayComplainService($channel['appid'], $channel['appkey'], $channel['appsecret']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');

        $params = [
            'channelId' => self::CHANNEL_ID,
            'beginDate' => $begin_date,
            'endDate' => $end_date,
            'offset' => 0,
            'limit' => $limit,
        ];
        try{
            $result = $this->service->execute('T001', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['data'])){
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
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $params = [
            'complaintId' => $data['thirdid'],
        ];
        try{
            $info = $this->service->execute('T002', $params);
            $replys = $this->service->execute('T003', $params);
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

        $data['money'] = round($info['complaintOrderInfo'][0]['amount']/100, 2);
        $data['images'] = [];
        if(!empty($info['complaintMediaList'])){
            foreach($info['complaintMediaList'] as $media){
                foreach($media['media_url'] as $media_url){
                    $data['images'][] = $this->getImageUrl($media_url);
                }
            }
        }
        $data['is_full_refunded'] = $info['complaintFullRefunded']; //订单是否已全额退款
        $data['incoming_user_response'] = $info['incomingUserResponse']; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['userComplaintTimes']; //用户投诉次数
        if($info['problemType'] == 'REFUND' && isset($info['applyRefundAmount'])){
            $data['apply_refund_amount'] = round($info['applyRefundAmount']/100, 2); //申请退款金额
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
        $thirdid = $info['complaintId'];
        $trade_no = $info['complaintOrderInfo'][0]['outTradeNo'];
        $api_trade_no = $info['complaintOrderInfo'][0]['transactionId'];
        $status = self::getStatus($info['complaintState']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['trade_no'=>$trade_no]);
            if(!$order){
                $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['bill_trade_no'=>$api_trade_no]);
                if($order){
                    $trade_no = $order['trade_no'];
                }else{
                    if(!$conf['complain_range']) return 0;
                    $trade_no = $api_trade_no;
                }
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
                $type = self::$problem_type_text[$info['problemType']] ?? '其他类型';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problemDescription'], 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['payerPhone'], 'addtime'=>$info['complaintTime'], 'edittime'=>$info['complaintTime'], 'thirdmchid'=>$info['complaintedMchId'], 'money'=>round($info['complaintOrderInfo'][0]['amount']/100, 2)]);

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
        $image = file_get_contents($filepath);
        $params = [
            'file' => base64_encode($image),
            'filename' => $filename,
            'sha256' => hash_file('sha256', $filepath),
        ];
        try{
            $result = $this->service->execute('T007', $params, true);
            return ['code'=>0, 'image_id'=>$result['media_id']];
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
        $params = [
            'complaintId' => $thirdid,
            'complaintedMchid' => $this->channel['thirdmchid'],
            'responseContent' => $content,
            'responseImages' => $images
        ];
        try{
            $result = $this->service->execute('T004', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        $params = [
            'complaintId' => $thirdid,
            'action' => $code == 1 ? 'APPROVE' : 'REJECT',
        ];
        if($code == 0){
            if($images === null) $images = [];
            $params += [
                'rejectReason' => $content,
                'rejectMediaList' => $images,
                'remark' => $remark
            ];
        }else{
            $params += [
                'launchRefundDay' => 0
            ];
        }
        try{
            $result = $this->service->execute('T006', $params);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $params = [
            'complaintId' => $thirdid,
            'complaintedMchid' => $this->channel['thirdmchid'],
        ];
        try{
            $result = $this->service->execute('T005', $params);
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
            'mediaId' => $media_id,
        ];
        try{
            $result = $this->service->execute('T008', $params);
            return base64_decode($result['file']);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
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

class HnapayComplainService extends \HnaPayApi
{
    protected $gateway_url = 'https://risk.hnapay.com/complaints/weixin';
    
    public function __construct($appid, $public_key, $private_key)
    {
        parent::__construct($appid, $public_key, $private_key);
    }

    public function execute($transCode, $bizParams){
        $body = json_encode($bizParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params = [
            'transCode' => $transCode,
            'transTime' => date('YmdHis'),
            'mchId' => $this->mer_id,
            'serialId' => date("YmdHis").rand(11111,99999),
            'body' => $body,
            'signType' => '1',
        ];
        if(isset($bizParams['file'])) unset($bizParams['file']);
        $signstr = json_encode($bizParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->rsaPrivateSign($signstr);
        $response = get_curl($this->gateway_url, json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        $arr = json_decode($response, true);
        if(isset($arr['code']) && $arr['code'] == 'SUCCESS'){
            return $arr['body'];
        }else{
            throw new Exception($arr['msg']?$arr['msg']:'接口请求失败');
        }
    }
}