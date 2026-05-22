<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'kuaiqian/inc/PayApp.class.php';

class KuaiqianAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new KuaiqianComplainService($channel);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 50 ? 50 : $num;
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
            if($result['pageNo'] == 1 && $result['totalCount'] == 0 || empty($result['data'])) break;

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
        $data['complain_url'] = $info['complaintUrl'] ?? '无';
        $data['images'] = [];
        if(!empty($info['certifyInfo'])){
            $certifyInfo = json_decode($info['certifyInfo'], true);
            foreach($certifyInfo as $certify){
                $data['images'][] = $certify;
            }
        }
        $data['reply_detail_infos'] = [];

        //商家处理进展
        $data['process_code'] = $info['processCode'];
        $data['process_message'] = $info['processCode'];
        $data['process_remark'] = $info['processRemark'];
        $data['process_img_url_list'] = [];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintNo'];
        $trade_no = $info['orderId'];
        $status = self::getStatus($info['complaintState']);
        
        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,subchannel', ['trade_no'=>$trade_no]);
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
                $time = $info['complaintTime'];
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'未知类型', 'title'=>'支付宝交易投诉', 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['complaintContact'], 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['alipaytMerchantId'], 'money'=>round($info['amount']/100, 2)]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->channel['thirdmchid'] = $info['alipaytMerchantId'];
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
            $image_id = $this->service->uploadImage($filepath, $filename, $thirdid);
            return ['code'=>0, 'image_id'=>$image_id];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        if($images && count($images) > 0){
            $img_file_list = [];
            foreach($images as $image){
                $arr = explode('|', $image);
                $img_file_list[] = ['alipayMediaId'=>$arr[0], 'alipayMediaUrl'=>$arr[1]];
            }
        }else{
            $img_file_list = null;
        }
        try{
            $this->service->feedbackSubmit($thirdid, $code, $content, $img_file_list);
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
        return ['code'=>-1, 'msg'=>'不支持该操作'];
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        return true;
    }

    private static function getStatus($status){
        $status = explode('-', $status)[0];
        if($status == 'WAIT_PROCESS' || $status == 'OVERDUE'){
            return 0;
        }elseif($status == 'PROCESSING' || $status == 'PART_OVERDUE'){
            return 1;
        }else{
            return 2;
        }
    }

}


class KuaiqianComplainService
{
    private $client;
    private $channel;

    function __construct($channel){
        $this->channel = $channel;
		$this->client = new \kuaiqian\PayApp($channel['appid'], $channel['appkey'], $channel['appsecret']);
	}

    private function requestApi($messageType, $params){
        $out_biz_no = date("YmdHis").rand(11111,99999);
        $head = [
            'version' => '1.0.0',
			'messageType' => $messageType,
			'memberCode' => $this->channel['appid'],
			'externalRefNumber' => $out_biz_no,
        ];
        $this->client->gateway_url = 'https://umgw.99bill.com/umgw-boss/common/distribute.html';
        $result = $this->client->execute($head, $params);
        if($result['bizResponseCode'] == '0000'){
            return $result;
        }else{
            throw new Exception('['.$result['bizResponseCode'].']'.$result['bizResponseMessage']);
        }
    }

    //查询投诉单列表
    public function batchQuery($begin_date, $end_date, $page_no = 1, $page_size = 10){
        $params = [
            'merchantId' => $this->channel['merchant_id'],
            'complaintSource' => 'ALIPAY_BILL',
            'startTime' => $begin_date,
            'endTime' => $end_date,
            'pageNo' => $page_no,
            'pageSize' => $page_size
        ];
        $result = $this->requestApi('TS003', $params);
        return $result;
    }

    //查询投诉单详情
    public function query($complaint_id)
    {
        $params = [
            'merchantId' => $this->channel['merchant_id'],
            'complaintNo' => $complaint_id,
            'complaintSource' => 'ALIPAY_BILL',
        ];
        $result = $this->requestApi('TS004', $params);
        return $result;
    }

    //反馈处理完成
    public function feedbackSubmit($complaint_id, $process_code, $remark, $img_file_list = null)
    {
        $params = [
            'merchantId' => $this->channel['merchant_id'],
            'complaintNo' => $complaint_id,
            'complaintSource' => 'ALIPAY_BILL',
            'processCode' => $process_code,
            'processRemark' => $remark,
        ];
        if ($img_file_list) $bizContent['complaintMediaList'] = $img_file_list;
        $result = $this->requestApi('TS007', $params);
        return $result['complaintProcessSuccess'];
    }

    //上传反馈图片
    public function uploadImage($file_path, $file_name, $complaint_id)
    {
        $params = [
            'merchantId' => $this->channel['merchant_id'],
            'complaintNo' => $complaint_id,
            'complaintSource' => 'ALIPAY_BILL',
            'file' => base64_encode(file_get_contents($file_path)),
            'fileName' => $file_name
        ];
        $result = $this->requestApi('TS001', $params);
        return $result['alipayMediaId'].'|'.$result['alipayMediaUrl'];
    }
}