<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'dinpay/inc/DinpayClient.php';

class DinpayWxpay implements IComplain
{

    static $paytype = 'wxpay';
    private $imgdir = ROOT.'assets/uploads/';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];
    private static $operate_type_text = ['USER_CREATE_COMPLAINT'=>'用户提交投诉', 'USER_CONTINUE_COMPLAINT'=>'用户继续投诉', 'USER_RESPONSE'=>'用户留言', 'PLATFORM_RESPONSE'=>'平台留言', 'MERCHANT_RESPONSE'=>'商户留言', 'MERCHANT_CONFIRM_COMPLETE'=>'商户申请结单', 'USER_CREATE_COMPLAINT_SYSTEM_MESSAGE'=>'用户提交投诉系统通知', 'COMPLAINT_FULL_REFUNDED_SYSTEM_MESSAGE'=>'投诉单发起全额退款系统通知', 'USER_CONTINUE_COMPLAINT_SYSTEM_MESSAGE'=>'用户继续投诉系统通知', 'USER_REVOKE_COMPLAINT'=>'用户主动撤诉', 'USER_COMFIRM_COMPLAINT'=>'用户确认投诉解决', 'PLATFORM_HELP_APPLICATION'=>'平台催办', 'USER_APPLY_PLATFORM_HELP'=>'用户申请平台协助', 'MERCHANT_APPROVE_REFUND'=>'商户同意退款申请', 'MERCHANT_REFUSE_RERUND'=>'商户拒绝退款申请', 'USER_SUBMIT_SATISFACTION'=>'用户提交满意度调查结果', 'SERVICE_ORDER_CANCEL'=>'服务订单已取消', 'SERVICE_ORDER_COMPLETE'=>'服务订单已完成'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \DinpayClient($channel['appid'], $channel['appkey'], $channel['appsecret']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);
        $begin_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');

        $params = [
            'interfaceName' => 'wxComplaintQuery',
            'merchantId' => $this->channel['appmchid'],
            'complaintStartDate' => $begin_date,
            'complaintEndDate' => $end_date
        ];
        try{
            $result = $this->service->execute('/api/merchantEntry/wxComplaintQuery', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $count_add = 0;
        $count_update = 0;
        if(!empty($result['wxComplaintList'])){
            foreach($result['wxComplaintList'] as $info){
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
            'interfaceName' => 'wxComplaintQuery',
            'merchantId' => $this->channel['appmchid'],
            'complaintId' => $thirdid
        ];
        try{
            $data = $this->service->execute('/api/merchantEntry/wxComplaintQuery', $params);
        } catch (Exception $e) {
            return false;
        }
        if(empty($data['wxComplaintList'])) return false;
        $info = $data['wxComplaintList'][0];
        $rescode = $this->updateInfo($info);

        $msgtype = null;
        if($rescode == 2){
            $msgtype = '用户提交了新的反馈，请尽快处理';
        }elseif($rescode == 1){
            $msgtype = '您有新的支付交易投诉，请尽快处理';
        }
        if($msgtype){
            CommUtil::sendMsg($msgtype, $thirdid);
        }
        return true;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $params = [
            'interfaceName' => 'wxComplaintQuery',
            'merchantId' => $this->channel['appmchid'],
            'complaintId' => $data['thirdid']
        ];
        try{
            $result = $this->service->execute('/api/merchantEntry/wxComplaintQuery', $params);
            $params['interfaceName'] = 'wxComplaintHistoryQuery';
            $replys = $this->service->execute('/api/merchantEntry/wxComplaintHistoryQuery', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        if(empty($result['wxComplaintList'])) return ['code'=>-1, 'msg'=>'投诉记录不存在'];
        $info = $result['wxComplaintList'][0];

        $status = self::getStatus($info['complaintState']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['images'] = [];
        $imgList = json_decode($info['imgFileId'], true);
        if(!empty($imgList)){
            foreach($imgList as $img){
                $data['images'][] = $this->getImageUrl($img['imgFileId']);
            }
        }
        $data['incoming_user_response'] = $info['replyState'] == 'INIT'; //是否有待回复的用户留言

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        foreach($replys['queryResponses'] as $row){
            $i++;
            if(empty($row['operateDetails'])) continue;
            $images = [];
            $imgList = json_decode($row['imgFileId'], true);
            if(!empty($imgList)){
                foreach($imgList as $img_id){
                    $images[] = $this->getImageUrl($img_id);
                }
            }
            $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$row['operateTime'], 'content'=>$row['operateDetails'], 'images'=>$images];
        }
        if(!empty($data['reply_detail_infos'])) {
            array_multisort(array_column($data['reply_detail_infos'], 'time'), SORT_DESC, $data['reply_detail_infos']);
            if($data['reply_detail_infos'][count($data['reply_detail_infos'])-1]['type'] == 'user'){
                $data['reply_detail_infos'][count($data['reply_detail_infos'])-1]['content'] = '发起投诉';
                $data['reply_detail_infos'][count($data['reply_detail_infos'])-1]['images'] = [];
            }
        }

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintId'];
        $trade_no = $info['orderNo'];
        $status = self::getStatus($info['complaintState']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'uid,channel,subchannel', ['trade_no'=>$trade_no]);
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
                $type = self::$problem_type_text[$info['problemType']] ?? '其他类型';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problemDescription'], 'content'=>$info['complaintDetail'], 'status'=>$status, 'addtime'=>$info['complaintDate'], 'edittime'=>$info['complaintDate']]);

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
        $tmp_path = $this->imgdir.'tmp/';
        if(!is_dir($tmp_path)) mkdir($tmp_path, 0777, true);
        $allow_ext = ['png','jpg','jpeg','bmp','gif'];
        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!in_array($file_ext, $allow_ext)){
            return ['code'=>-1, 'msg'=>'图片格式必须为：png、bmp、gif、jpg、jpeg'];
        }
        if(filesize($filepath) > 2*1024*1024){
            return ['code'=>-1, 'msg'=>'图片大小不能超过2M'];
        }
        $file_name = md5_file($filepath).'.'.$file_ext;
        if(move_uploaded_file($filepath, $tmp_path.$file_name)){
            return ['code'=>0, 'image_id'=>$file_name];
        }else{
            return ['code'=>-1, 'msg'=>'图片上传失败'];
        }
    }

    //返回图片完整路径
    private function checkImage($image_id){
        if(empty($image_id)) return null;
        $allow_ext = ['png','jpg','jpeg','bmp','gif'];
        $path_info = pathinfo($image_id);
        if(!in_array($path_info['extension'], $allow_ext)){
            throw new Exception($image_id.'图片格式不正确');
        }
        if(!preg_match('/^[0-9a-z]{32}$/i', $path_info['filename'])){
            throw new Exception($image_id.'图片路径不正确');
        }
        $tmp_path = $this->imgdir.'tmp/';
        return $tmp_path.$image_id;
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        $result = $this->replySubmit($thirdid, $content, $images);
        return $result;
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        if($images === null) $images = [];
        if(count($images) > 1){
            return ['code'=>-1, 'msg'=>'最多上传1张图片'];
        }
        $params = [
            'interfaceName' => 'wxComplaintReply',
            'merchantId' => $this->channel['appmchid'],
            'complaintId' => $thirdid,
            'replyContent' => $content,
            'fileSign' => md5('')
        ];
        $file = true;
        if(!empty($images)){
            $file_path = $this->checkImage($images[0]);
            $file = new \CURLFile($file_path, null, basename($file_path));
            $params['fileSign'] = md5_file($file_path);
        }
        try{
            $result = $this->service->execute('/api/merchantEntry/wxComplaintReply', $params, $file);
            return ['code'=>0, 'data'=>$result];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        return ['code'=>-1, 'msg'=>'不支持该操作'];
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        return ['code'=>-1, 'msg'=>'不支持该操作，请使用回复用户处理投诉'];
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        $params = [
            'interfaceName' => 'wxComplaintAttachment',
            'merchantId' => $this->channel['appmchid'],
            'imgFileId' => $media_id
        ];
        try{
            $image = $this->service->execute('/api/merchantEntry/wxComplaintDownload', $params, null, true);
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

    private function getImageUrl($img_id){
        return './download.php?act=wximg&channel='.$this->channel['id'].'&subchannel='.$this->channel['subid'].'&mediaid='.$img_id;
    }
}