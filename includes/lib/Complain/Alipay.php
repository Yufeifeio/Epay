<?php
namespace lib\Complain;

use Exception;

class Alipay implements IComplain
{

    static $paytype = 'alipay';

    private $channel;
    private $service;

    private static $status_text = ['MERCHANT_PROCESSING'=>'商家处理中', 'MERCHANT_FEEDBACKED'=>'商家已反馈', 'FINISHED'=>'投诉已完结', 'CANCELLED'=>'投诉已撤销', 'PLATFORM_PROCESSING'=>'平台处理中', 'PLATFORM_FINISH'=>'平台处理完结', 'CLOSED'=>'系统关闭'];
    private static $role_text = ['USER'=>'用户', 'MERCHANT'=>'商家', 'SYSTEM'=>'系统', 'AUDITOR'=>'审核小二', 'GOVERNMENT'=>'政府单位'];

    function __construct($channel){
		$this->channel = $channel;
        $alipay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
        $this->service = new \Alipay\AlipayComplainService($alipay_config);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);

        $count_add = 0;
        $count_update = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            try{
                $result = $this->service->batchQuery(null, null, null, $page_num, $page_size);
            } catch (Exception $e) {
                return ['code'=>-1, 'msg'=>$e->getMessage()];
            }
            if($result['total_num'] == 0 || count($result['trade_complain_infos']) == 0) break;

            foreach($result['trade_complain_infos'] as $info){
                $rescode = $this->updateInfo($info);
                if($rescode == 2) $count_update++;
                elseif($rescode == 1) $count_add++;
            }

            if($page_num >= $result['total_page_num']) break;
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
        $user_last_reply = $info['reply_detail_infos'][0]['replier_role']=='USER';
        $retcode = $this->updateInfo($info, true);

        //发送消息通知
        $msgtype = null;
        if($retcode == 2){
            if($user_last_reply && $info['status'] == 'MERCHANT_PROCESSING'){
                $msgtype = '用户提交了新的反馈，请尽快处理';
            }
            elseif($user_last_reply && $info['status'] == 'PLATFORM_PROCESSING'){
                $msgtype = '平台处理中，可继续补充交易凭证';
            }
        }elseif($retcode == 1){
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
        try{
            $info = $this->service->query($data['thirdid']);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        if($data['edittime'] != $info['gmt_modified']){
            $status = self::getStatus($info['status']);
            $data['edittime'] = $info['gmt_modified'];
            $DB->update('complain', ['status'=>$status, 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            if($status != $data['status']){
                CommUtil::autoHandle($data['trade_no'], $status);
                $data['status'] = $status;
            }
        }

        $data['money'] = $info['trade_amount'];
        $data['images'] = $info['images'];
        $data['status_text'] = self::$status_text[$info['status']]; //投诉单明细状态
        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        foreach($info['reply_detail_infos'] as $row){
            $i++;
            if(!isset($row['content']) && $i == 1 && $data['status']==2) $row['content'] = '投诉完结';
            $role = self::$role_text[$row['replier_role']];
            if($row['replier_role']=='USER' && $i == count($info['reply_detail_infos'])){
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['replier_role']), 'name'=>$row['replier_name'].'（'.$role.'）', 'time'=>$row['gmt_create'], 'content'=>'发起投诉', 'images'=>[]];
            }else{
                $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['replier_role']), 'name'=>$row['replier_name'].'（'.$role.'）', 'time'=>$row['gmt_create'], 'content'=>$row['content'], 'images'=>$row['images']];
            }
        }

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }

    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complain_event_id'];
        $trade_no = $info['merchant_order_no'];
        $status = self::getStatus($info['status']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            if(strlen($trade_no) > 19){
                $sub_order = $DB->find('suborder', 'trade_no', ['sub_trade_no'=>$trade_no]);
                if($sub_order){
                    $trade_no = $sub_order['trade_no'];
                }
            }
            $order = $DB->find('order', 'trade_no,uid,subchannel', ['trade_no'=>$trade_no]);
            if(!$order){
                if(!$conf['complain_range']) return 0;
            }
        }

        if($row){
            if($row['edittime'] != $info['gmt_modified']){
                $DB->update('complain', ['status'=>$status, 'edittime'=>$info['gmt_modified']], ['id'=>$row['id']]);
                if($status != $row['status']){
                    CommUtil::autoHandle($trade_no, $status);
                }
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$info['leaf_category_name'], 'title'=>$info['complain_reason'], 'content'=>$info['content'], 'status'=>$status, 'phone'=>$info['phone_no'], 'addtime'=>$info['gmt_create'], 'edittime'=>$info['gmt_modified'], 'money'=>$info['trade_amount']]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $this->feedbackSubmit($thirdid, '03', $conf['complain_auto_reply_con']);
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
            $image_id = $this->service->imageUpload($filepath, $filename);
            return ['code'=>0, 'image_id'=>$image_id];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code) || $code == 'ORTHER') $code = '03';
        $images = !empty($images) ? implode(',', $images) : null;
        try{
            $this->service->feedbackSubmit($thirdid, $code, $content, $images);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        $images = !empty($images) ? implode(',', $images) : null;
        try{
            $this->service->replySubmit($thirdid, $content, $images);
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
        return false;
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        $images = !empty($images) ? implode(',', $images) : null;
        try{
            $this->service->supplementSubmit($thirdid, $content, $images);
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
        if($status == 'MERCHANT_PROCESSING'){
            return 0;
        }elseif($status == 'MERCHANT_FEEDBACKED' || $status == 'PLATFORM_PROCESSING'){
            return 1;
        }else{
            return 2;
        }
    }

    private static function getUserType($type){
        if($type == 'USER'){
            return 'user';
        }elseif($type == 'MERCHANT'){
            return 'merchat';
        }else{
            return 'system';
        }
    }
}