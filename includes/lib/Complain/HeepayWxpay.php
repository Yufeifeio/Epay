<?php
namespace lib\Complain;

use Exception;

class HeepayWxpay implements IComplain
{

    static $paytype = 'wxpay';

    private $channel;
    private $service;

    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];

    function __construct($channel){
		$this->channel = $channel;
        $this->service = new HeepayClient($channel['appid'],$channel['mch_private_key']);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $limit = $num > 50 ? 50 : intval($num);
        $begin_date = date('Ymd', strtotime('-6 days'));
        $end_date = date('Ymd');

        $params = [
            'complaint_begin_time' => $begin_date,
            'complaint_end_time' => $end_date,
        ];
        try{
            $result = $this->service->submit('heepay.query.channel.complaint.recordlist', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        $count_add = 0;
        $count_update = 0;
        if(!empty($result['complaint_list'])){
            foreach($result['complaint_list'] as $info){
                if($info['complaint_source'] != '1') continue;
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
            'complaint_begin_time' => date('Ymd', strtotime($data['addtime'])),
            'complaint_end_time' => date('Ymd', strtotime($data['addtime']) + 86400),
            'agent_bill_id' => $data['trade_no'],
        ];
        $params2 = [
            'complaint_id' => $data['thirdid']
        ];
        try{
            $result = $this->service->submit('heepay.query.channel.complaint.recordlist', $params);
            if(empty($result['complaint_list'])) return ['code'=>-1, 'msg'=>'未找到对应的投诉记录'];
            $info = $result['complaint_list'][0];
            $replys = $this->service->submit('heepay.query.channel.complaint.detaillist', $params2);
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
        $data['is_full_refunded'] = $info['complaint_full_refunded'] == 'True'; //订单是否已全额退款
        $data['incoming_user_response'] = $info['incoming_user_response'] == 'True'; //是否有待回复的用户留言
        $data['user_complaint_times'] = $info['user_complaint_times']; //用户投诉次数
        if($info['problem_type'] == 'REFUND' && isset($info['apply_refund_amount'])){
            $data['apply_refund_amount'] = round($info['apply_refund_amount']/100, 2); //申请退款金额
        }

        $data['reply_detail_infos'] = []; //协商记录
        $i = 0;
        if(!empty($replys['detail_list'])){
            foreach($replys['detail_list'] as $row){
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
        $thirdid = $info['complaint_id'];
        $trade_no = $info['agent_bill_id'];
        $api_trade_no = $info['bill_no'];
        $status = self::getStatus($info['complaint_state']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'trade_no,uid,channel,subchannel', ['trade_no'=>$trade_no]);
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
                $type = self::$problem_type_text[$info['problem_type']] ?? '交易投诉';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problem_description'] ?? '-', 'content'=>$info['complaint_detail'], 'status'=>$status, 'phone'=>$info['payer_phone'], 'addtime'=>$info['complaint_time'], 'edittime'=>$info['complaint_time'], 'thirdmchid'=>$info['channel_mch_id'], 'money'=>round($info['amount']/100, 2)]);

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
            'complaint_id' => $thirdid,
            'complaint_source' => '1',
            'file_content' => base64_encode(file_get_contents($filepath)),
        ];
        try{
            $result = $this->service->submit('heepay.query.channel.complaint.uploadimage', $params, true);
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
            'complaint_id' => $thirdid,
            'operate_details' => $content,
        ];
        $i = 1;
        foreach($images as $image){
            $params['image'.$i++] = $image;
        }
        try{
            $result = $this->service->submit('heepay.query.channel.complaint.submit.message', $params);
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
        $params = [
            'complaint_id' => $thirdid,
            'complaint_source' => 1,
        ];
        try{
            $result = $this->service->submit('heepay.query.channel.complaint.complete', $params);
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
        return false;
    }

    private static function getStatus($status){
        if($status == '1'){
            return 0;
        }elseif($status == '2'){
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
}

class HeepayClient
{
	private $version = '1.0';
	private $mchid;
	private $platform_public_key = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzaJvBXXBzCtZv3XXM6zFxNW2M7tc1h/gKTcEwevQyk9Zb/WnEJTsRudRZwShqqYbzAPe1eC6dei5YhEHd2PXl+ZjGUAKScjhfxpl5gOBKoRT+0vy337PgmmuLiSmRSWpRWWuxFwT1ZxZgiNnyI4PY3+Joed5Csb83nqimOipZl0DNIoZvWFJ3eGeSoWN0EQ7mZ/HycuG1wFjBcn3UvwxGbjIs6x36AXmBE6H8RTSQdDhH4wmEX2d8KJwAWdUhPZGhcxQp5PGA6eoKdr5nL/iGn7SkxOGUnCam+AGUBlJiFic8uElpvlzry2JJdFduIe1koJHVgk1q4mgQ5xjt1uQMwIDAQAB';
	private $merchant_private_key;

	public function __construct($mchid, $merchant_private_key)
	{
		$this->mchid = $mchid;
		$this->merchant_private_key = $merchant_private_key;
	}

	//发起API请求
	public function submit($method, $biz_content){
		$requrl = 'https://pay.heepay.com/API/Index.aspx';
		$data = json_encode($biz_content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$params = [
			'method' => $method,
			'version' => $this->version,
			'merch_id' => $this->mchid,
			'timestamp' => date('Y-m-d H:i:s'),
            'biz_content' => $data,
		];

		$params['sign'] = $this->generateSign($params);
        $params['biz_content'] = json_decode($params['biz_content'], true);

		$response = get_curl($requrl, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
		$result = json_decode($response, true);
		if(isset($result['code']) && $result['code']==10000){
			/*if(!$this->verifySign($result)){
				throw new Exception('返回数据验签失败');
			}*/
			return json_decode($result['data'], true);
		}elseif(isset($result['sub_msg'])){
			throw new Exception($result['sub_msg']);
		}elseif(isset($result['msg'])){
			throw new Exception($result['msg']);
		}else{
			throw new Exception('返回数据解析失败');
		}
	}

	//获取待签名字符串
	private function getSignContent($param){
		ksort($param);
		$signstr = '';
	
		foreach($param as $k => $v){
			if($k != "sign" && $v !== null && $v !== ''){
				$signstr .= $k.'='.$v.'&';
			}
		}
		$signstr = substr($signstr,0,-1);
		return $signstr;
	}

	//请求参数签名
	private function generateSign($param){
		return $this->rsaPrivateSign($this->getSignContent($param));
	}

	//验签方法
	public function verifySign($param){
		if(empty($param['sign'])) return false;
		return $this->rsaPubilcVerify($this->getSignContent($param), $param['sign']);
	}

	//商户私钥签名
	private function rsaPrivateSign($data){
		$priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
		$pkeyid = openssl_pkey_get_private($res);
		if(!$pkeyid){
			throw new Exception('签名失败，商户私钥不正确');
		}
		openssl_sign($data, $signature, $pkeyid);
		$signature = base64_encode($signature);
		return $signature;
	}

	//平台公钥验签
	private function rsaPubilcVerify($data, $signature){
		$pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
		$pubkeyid = openssl_pkey_get_public($res);
		if(!$pubkeyid){
			throw new Exception('验签失败，平台公钥不正确');
		}
		$result = openssl_verify($data, base64_decode($signature), $pubkeyid);
		return $result === 1;
	}
}