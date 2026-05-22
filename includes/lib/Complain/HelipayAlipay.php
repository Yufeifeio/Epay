<?php
namespace lib\Complain;

use Exception;

require_once PLUGIN_ROOT.'helipay/inc/DES3.class.php';

class HelipayAlipay implements IComplain
{

    static $paytype = 'alipayrisk';
    private $imgdir = ROOT.'assets/uploads/';

    private $channel;
    private $merchantNo;

    private static $entryApiUrl = 'http://entry.trx.helipay.com/trx/merchantEntry/interface.action';
    private static $entryUploadUrl = 'http://entry.trx.helipay.com/trx/merchantEntry/upload.action';
    private static $entryDownloadUrl = 'http://entry.trx.helipay.com/trx/merchantEntry/download.action';
    private static $problem_type_text = ['REFUND'=>'申请退款', 'SERVICE_NOT_WORK'=>'服务权益未生效', 'OTHERS'=>'其他类型'];
    private static $process_code = ['CONSENSUS_WITH_CLIENT'=>'已联系到用户，协商一致，无异议', 'ORTHER'=>'其他', 'RECTIFICATION_NO_REFUND'=>'不涉及退款，已针对投诉内容进行整改', 'REFUND'=>'已退款，用户无异议', 'SUBMIT_PROOF_NOT_CONTACTED'=>'已提交证明材料', 'OTHER_CHANNEL_REFUND'=>'其他处理码', 'UNKNOWN'=>'未知返回码'];

    function __construct($channel){
		$this->channel = $channel;
        $this->merchantNo = !empty($channel['appmchid']) ? $channel['appmchid'] : $channel['appid'];
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);
        $begin_date = date('Ymd', strtotime('-6 days'));
        $end_date = date('Ymd');

        $params = [
            'appPayType' => 'ALIPAY',
            'merchantNo' => $this->merchantNo,
            'complaintStartDate' => $begin_date,
            'complaintEndDate' => $end_date
        ];
        try{
            $result = $this->execute('wxComplaintQuery', $params);
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
            'appPayType' => 'ALIPAY',
            'merchantNo' => $this->merchantNo,
            'complaintId' => $thirdid
        ];
        try{
            $result = $this->execute('wxComplaintQuery', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        if(empty($result)) return false;
        $info = $result[0];
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
            'appPayType' => 'ALIPAY',
            'merchantNo' => $this->merchantNo,
            'complaintId' => $data['thirdid']
        ];
        try{
            $result = $this->execute('wxComplaintQuery', $params);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        if(empty($result)) return ['code'=>-1, 'msg'=>'投诉记录不存在'];
        $info = $result[0];

        $status = self::getStatus($info['complaintState']);
        if($status != $data['status']){
            $data['status'] = $status;
            $data['edittime'] = date('Y-m-d H:i:s');
            $DB->update('complain', ['status'=>$data['status'], 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
        }

        $data['money'] = $info['amount'];
        $data['complain_url'] = $info['complainUrl'] ?? '无';
        $data['images'] = [];
        $imgList = json_decode($info['imgFileId'], true);
        if(!empty($imgList)){
            foreach($imgList as $img){
                $data['images'][] = $img['imgFileId'];
            }
        }
        $data['reply_detail_infos'] = []; //协商记录

        //商家处理进展
        $data['process_code'] = $info['processCode'];
        $data['process_message'] = self::$process_code[$info['processCode']] ?? '未知返回码';
        $data['process_remark'] = $info['processRemark'];
        $data['process_img_url_list'] = $info['processImgUrlList'] ?? [];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }
    
    private function updateInfo($info){
        global $DB, $conf;
        $thirdid = $info['complaintId'];
        $trade_no = $info['orderNum'];
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
                CommUtil::autoHandle($trade_no, $status);
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $type = self::$problem_type_text[$info['problemType']] ?? '其他类型';
                $subchannel = $order ? $order['subchannel'] : ($this->channel['subid'] ?? 0);
                $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'subchannel'=>$subchannel, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>$type, 'title'=>$info['problemDescription'] ?? '-', 'content'=>$info['complaintDetail'], 'status'=>$status, 'phone'=>$info['payerPhone'], 'addtime'=>$info['complaintDate'], 'edittime'=>$info['complaintDate'], 'money'=>$info['amount']]);

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
        if(empty($code)) $code = 'ORTHER';
        if($images === null) $images = [];
        if(count($images) > 1){
            return ['code'=>-1, 'msg'=>'最多上传1张图片'];
        }
        $params = [
            'appPayType' => 'ALIPAY',
            'merchantId' => $this->merchantNo,
            'complaintId' => $thirdid,
            'replyContent' => $content,
            'processCode' => $code,
            'fileSign' => md5('')
        ];
        $file = true;
        if(!empty($images)){
            $file_path = $this->checkImage($images[0]);
            $file = new \CURLFile($file_path, null, basename($file_path));
            $params['fileSign'] = md5_file($file_path);
        }
        try{
            $result = $this->execute('wxComplaintReply', $params, $file);
            return ['code'=>0, 'data'=>$result];
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

    private function execute($interfaceName, $bizParams, $file = null, $is_download = false){
        $des = new \DES3();
        $body = $des->encrypt2(json_encode($bizParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->channel['public_enckey']);
        $source = $body."&".$this->channel['appid']."&".$this->channel['public_signkey'];
        $sign = md5($source);
        $url = $file ? self::$entryUploadUrl : ($is_download ? self::$entryDownloadUrl : self::$entryApiUrl);
        $params = [
            'merchantNo' => $this->channel['appid'],
            'interfaceName' => $interfaceName,
            'body' => $body,
            'sign' => $sign,
            'signType' => 'MD5',
        ];
        if($file){
            if($file instanceof \CURLFile){
				$params['file'] = $file;
			}
        }
        $response = get_curl($url, $file ? $params : http_build_query($params));
        if($is_download) return $response;
        $result = json_decode($response, true);
        if(isset($result['code']) && $result['code'] == '0000'){
            $decrypted = $des->decrypt2($result['data'], $this->channel['public_enckey']);
            if(!$decrypted) throw new Exception('返回数据解密失败');
            return json_decode($decrypted, true);
        }elseif(isset($result['message'])){
            throw new Exception($result['message']);
        }else{
            throw new Exception('接口请求失败');
        }
    }
}