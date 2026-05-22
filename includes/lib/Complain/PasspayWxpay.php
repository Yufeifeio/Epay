<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'passpay/inc/PasspayClient.php';

class PasspayWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new \PasspayClient($channel['appurl'], $channel['appid'], $channel['appkey'], $channel['appsecret']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 1000 ? 1000 : $num;

        $params = [
            'limit' => $limit
        ];
        try{
            $result = $this->service->execute('index/ComplaintQuery', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }

        $count_add = 0;
        $count_update = 0;
        if(!empty($result)){
            foreach($result as $info){
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
        $info = json_decode($data['info'], true);

        $data['images'] = [];
        if(!empty($info['complaint_media_list'])){
            $imgList = json_decode($info['complaint_media_list'], true);
            foreach($imgList as $media){
                foreach($media['media_url'] as $media_url){
                    $data['images'][] = $media_url;
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

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['id'];
        $trade_no = $info['out_trade_no'];
        $api_trade_no = $info['transaction_id'];
        $status = self::getStatus($info['complaint_state']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'uid', ['trade_no'=>$trade_no]);
            if(!$order){
                $order = $DB->find('order', 'trade_no,uid', ['api_trade_no'=>$trade_no]);
                if($order){
                    $trade_no = $order['trade_no'];
                }else{
                    $order = $DB->find('order', 'trade_no,uid', ['bill_trade_no'=>$api_trade_no]);
                    if($order){
                        $trade_no = $order['trade_no'];
                    }else{
                        if(!$conf['complain_range']) return 0;
                    }
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
                $type = self::$problem_type_text[$info['problem_type']] ?? '其他类型';
                $content = json_encode($info);
                $time = date('Y-m-d H:i:s', $info['complaint_time']);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'], 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$info['payer_phone'], 'addtime'=>$time, 'edittime'=>$time, 'thirdmchid'=>$info['complainted_mchid'], 'info'=>$content]);

                CommUtil::autoHandle($trade_no, $status);
                return 1;
            }
        }
        return 0;
    }

    //上传图片
    public function uploadImage($thirdid, $filepath, $filename){
        return ['code'=>-1, 'msg'=>'不支持该操作'];
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        return ['code'=>-1, 'msg'=>'不支持该操作'];
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        return ['code'=>-1, 'msg'=>'不支持该操作'];
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
}