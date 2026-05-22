<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'kunpeng/inc/KunpengClient.php';

class KunpengWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \KunpengClient($channel['appid']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        return ['code'=>-1, 'msg'=>'鲲鹏支付不支持拉取投诉列表，只能通过回调更新'];
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        if(empty($type)) return;
        $retcode = $this->updateInfo($type);

        //发送消息通知
        $msgtype = null;
        if($retcode == 2){
            $msgtype = '用户提交了新的反馈，请尽快处理';
        }elseif($retcode == 1){
            $msgtype = '您有新的支付交易投诉，请尽快处理';
        }
        if($msgtype){
            CommUtil::sendMsg($msgtype, $thirdid);
        }
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        $params = [
            'complaintId' => $data['thirdid'],
            'channelType' => 'T',
        ];
        try{
            $info = $this->service->execute('/mas/atComplaint/queryComplaint.do', $params);
            $params['offSet'] = 0;
            $params['pageSize'] = 300;
            $replys = $this->service->execute('/order/complaint/complaintHistory', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['complaintStatus']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = $info['complaintAmount'];
        $data['images'] = [];

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        if(!empty($replys['complaintHisInfo'])){
            $list = json_decode($replys['complaintHisInfo'], true);
            foreach($list as $row){
                $i++;
                if(empty($row['operate_details'])) continue;
                $time = $row['operate_time'];
                $images = json_decode($row['image_list'], true);
                if($row['operator']=='投诉人' && $i == 1){
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>'发起投诉', 'images'=>[]];
                }else{
                    $data['reply_detail_infos'][] = ['type'=>self::getUserType($row['operator']), 'name'=>$row['operator'], 'time'=>$time, 'content'=>$row['operate_details'], 'images'=>$images];
                }
            }
        }

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintId'];
        $trade_no = $info['complaintLocalOrderNo'];
        $api_trade_no = $info['complaintChannelOrderNo'];
        $status = self::getStatus($info['complaintStatus']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['bill_trade_no'=>$api_trade_no]);
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
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complaintDescription'], 'status'=>$status, 'phone'=>$info['complaintPhone'], 'addtime'=>$info['complaintCreateTime'], 'edittime'=>$info['complaintCreateTime'], 'thirdmchid'=>$info['complaintChannelMerchantNo'], 'money'=>$info['complaintAmount']]);

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
        $params = [
            'file' => new \CURLFile($filepath, null, $filename),
        ];
        try{
            $result = $this->service->execute('/mas/complaints/wxUpload.do', $params, false, true, true);
            return ['code'=>0, 'image_id'=>$result['mediaId']];
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
        $params = [
            'complaintId' => $thirdid,
            'complaintedMchid' => $this->channel['thirdmchid'],
            'responseContent' => $content,
        ];
        if(!empty($images)) $params['responseImages'] = $images;
        try{
            $result = $this->service->execute('/mas/complaints/replyUser.do', $params);
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
            $result = $this->service->execute('/mas/complaints/updateRefProgress.do', $params);
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
            $result = $this->service->execute('/mas/complaints/complete.do', $params);
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
            $result = $this->service->execute('/mas/complaints/wxMediaData.do', $params, true);
            return $result;
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
        return './download.php?act=wximg&channel='.$this->channel['id'].'&mediaid='.$media_id;
    }
}