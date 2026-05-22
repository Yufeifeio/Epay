<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'haipay/inc/HaiPayClient.php';

class HaipayAlipay implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
		$this->service = new \HaiPayClient($channel['accessid'], $channel['accesskey']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);

        $params = [
            'transFirstLevelAgentNum' => $this->channel['agent_no'],
            'current' => 1,
            'size' => $limit,
        ];
        try{
            $result = $this->service->mchRequest('/api/v1/complaint/ali/queryListPager', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['list'])){
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
        $params = [
            'complaintId' => $thirdid,
        ];
        try{
            $info = $this->service->mchRequest('/api/v1/complaint/ali/detail', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
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
            'complaintId' => $data['thirdid'],
        ];
        try{
            $info = $this->service->mchRequest('/api/v1/complaint/ali/detail', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $status = self::getStatus($info['status']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = $info['gmtProcess'];
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
        }

        $data['money'] = $info['complainAmount'];
        $data['complain_url'] = $info['complainUrl'] ?? '无';
        $data['images'] = $info['certifyInfo'] ?? [];
        $data['status_text'] = $info['statusDescription']; //投诉单明细状态
        $data['reply_detail_infos'] = []; //协商记录

        //商家处理进展
        $data['process_code'] = $info['processCode'];
        $data['process_message'] = $info['processMessage'];
        $data['process_remark'] = $info['processRemark'];
        $data['process_img_url_list'] = $info['processImgUrlList'] ?? [];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintId'];
        $trade_no = $info['bussOrderNo'];
        $api_trade_no = $info['tradeNo'];
        $status = self::getStatus($info['complainStatus']);
        
        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,type,channel,subchannel', ['api_trade_no'=>$trade_no]);
            if($order){
                $trade_no = $order['trade_no'];
            }else{
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
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complainContent'], 'status'=>$status, 'phone'=>$info['contact'], 'addtime'=>date('Y-m-d H:i:s', $info['gmtComplain']/1000), 'edittime'=>date('Y-m-d H:i:s', $info['gmtProcess']/1000), 'money'=>$info['complainAmount']]);

                if($status == 0 && $conf['complain_auto_reply'] >= 1 && $conf['complain_auto_reply'] <= 2 && !empty($conf['complain_auto_reply_con'])){
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
            'complaintId' => $thirdid,
            'channelName' => 'alipay',
            'file' => base64_encode($image)
        ];
        try{
            $result = $this->service->mchRequest('/api/v1/complaint/upload', $params, true);
            return ['code'=>0, 'image_id'=>$result['fileKey'] . '|' . $result['fileUrl']];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        $img_file_list = [];
        if($images && count($images) > 0){
            foreach($images as $image){
                $arr = explode('|', $image);
                $img_file_list[] = ['imgUrl'=>$arr[1], 'imgUrlKey'=>$arr[0]];
            }
        }
        $params = [
            'idList' => [$thirdid],
            'processCode' => $code,
            'remark' => $content,
            'imgFileList' => $img_file_list,
        ];
        try{
            $this->service->mchRequest('/api/v1/complaint/ali/processFinish', $params, true);
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
        return false;
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        return false;
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
        if($status == 'WAIT_PROCESS' || $status == 'OVERDUE'){
            return 0;
        }elseif($status == 'PROCESSING' || $status == 'PART_OVERDUE'){
            return 1;
        }else{
            return 2;
        }
    }
}