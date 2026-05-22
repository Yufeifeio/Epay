<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'yinyingtong/inc/M2Client.php';

class YinyingtongWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;
    private $imgdir = ROOT.'assets/uploads/';

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \M2Client($channel['appid'], $channel['appkey']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        if(!empty($this->channel['channel_merch_no'])){
            $mchids = explode(',', $this->channel['channel_merch_no']);
        }else{
            global $DB;
            $mchids = [];
            $merchants = $DB->getAll("SELECT sub_mchid FROM pre_applymerchant WHERE cid IN (SELECT id FROM pre_applychannel WHERE channel=?) AND status>=4 AND sub_mchid<>''", [$this->channel['id']]);
            foreach($merchants as $row){
                $mchids = array_merge($mchids, explode('|', $row['sub_mchid']));
            }
            if(empty($mchids)){
                return ['code'=>-1, 'msg'=>'渠道商户号不能为空'];
            }
            $mchids = array_filter(array_unique($mchids), function($v){ return strlen($v) <= 10; });
        }
        $page_num = 1;
        $page_size = $num > 50 ? 50 : $num;
        $page_count = ceil($num / $page_size);
        $begin_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');
        $params = [
            'limit' => $page_size,
            'offset' => ($page_num - 1) * $page_size,
            'begin_date' => $begin_date,
            'end_date' => $end_date,
        ];

        $count_add = 0;
        $count_update = 0;
        foreach($mchids as $mchid){
            $params['complainted_mchid'] = $mchid;
            try{
                $result = $this->service->execute('gas_partn_api@complaints_list', $params);
                if($result['op_ret_subcode'] != '000000'){
                    continue;
                    //return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
                }
            } catch (Exception $e) {
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
            $result = json_decode($result['obj'], true);
            if($page_num == 1 && $result['total_count'] == 0 || empty($result['data'])) continue;

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
        $thirdid = explode('|', $thirdid);
        $params = [
            'complaint_id' => $thirdid[0],
            'complainted_mchid' => $thirdid[1],
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_detail', $params);
            if($result['op_ret_subcode'] != '000000'){
                return false;
            }
            $info = json_decode($result['obj'], true);
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
        $params = [
            'complaint_id' => $data['thirdid'],
            'complainted_mchid' => $this->channel['thirdmchid'],
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_detail', $params);
            if($result['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
            }
            $info = json_decode($result['obj'], true);
            $params['limit'] = 50;
            $params['offset'] = 0;
            $result2 = $this->service->execute('gas_partn_api@complaints_historys', $params);
            if($result2['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result2['op_ret_subcode'].']'.$result2['op_err_submsg']];
            }
            $replys = json_decode($result2['obj'], true);
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
        if(!empty($replys['data'])){
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
        }

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
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['api_trade_no'=>$trade_no]);
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
                $time = date('Y-m-d H:i:s', strtotime($info['complaint_time']));
                $type = self::$problem_type_text[$info['problem_type']] ?? '交易投诉';
                $phone = $info['payer_phone'];
                if(strlen($phone) > 20) $phone = null;
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                if(!$order){
                    $apply = $DB->getRow("SELECT uid FROM pre_applymerchant WHERE sub_mchid LIKE ? LIMIT 1", ['%'.$info['complainted_mchid'].'%']);
                    if($apply) $order['uid'] = $apply['uid'];
                }
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'] ?? '-', 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$info['payer_phone'], 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['complainted_mchid'], 'money'=>round($info['complaint_order_info'][0]['amount']/100, 2)]);

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
        global $siteurl;
        $tmp_path = $this->imgdir.'tmp/';
        if(!is_dir($tmp_path)) mkdir($tmp_path, 0777, true);
        $allow_ext = ['png','jpg','jpeg'];
        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!in_array($file_ext, $allow_ext)){
            return ['code'=>-1, 'msg'=>'图片格式必须为：png、bmp、gif、jpg、jpeg'];
        }
        if(filesize($filepath) > 2*1024*1024){
            return ['code'=>-1, 'msg'=>'图片大小不能超过2M'];
        }
        $file_name = md5_file($filepath).'.'.$file_ext;
        if(!move_uploaded_file($filepath, $tmp_path.$file_name)){
            return ['code'=>-1, 'msg'=>'图片上传失败'];
        }
        $image_url = $siteurl.'assets/uploads/tmp/'.$file_name;
        /*try{
            $image_url = $this->service->upload($filepath, $filename);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }*/
        $params = [
            'image_url' => $image_url,
            'image_suffix' => $file_ext,
            'complainted_mchid' => $this->channel['thirdmchid'],
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_upload', $params, true);
            if($result['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
            }
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
        global $DB;
        if($images === null) $images = [];
        $params = [
            'complainted_mchid' => $this->channel['thirdmchid'] ?? $DB->findColumn('complain', 'thirdmchid', ['thirdid'=>$thirdid]),
            'complaint_id' => $thirdid,
            'response_content' => $content,
            'response_images' => $images,
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_response', $params);
            if($result['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
            }
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        $params = [
            'complaint_id' => $thirdid,
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
            $result = $this->service->execute('gas_partn_api@complaints_updateRefundProgress', $params);
            if($result['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
            }
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        $params = [
            'complainted_mchid' => $this->channel['thirdmchid'],
            'complaint_id' => $thirdid,
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_complete', $params);
            if($result['op_ret_subcode'] != '000000'){
                return ['code'=>-1, 'msg'=>'['.$result['op_ret_subcode'].']'.$result['op_err_submsg']];
            }
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
            'media_id' => $media_id,
            'complainted_mchid' => trim($_GET['thirdmchid']),
        ];
        try{
            $result = $this->service->execute('gas_partn_api@complaints_upload_query', $params);
            if($result['op_ret_subcode'] == '000000'){
                return get_curl($result['url']);
            }
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
        return './download.php?act=wximg&channel='.$this->channel['id'].'&mediaid='.$media_id.'&thirdmchid='.$this->channel['thirdmchid'];
    }
}