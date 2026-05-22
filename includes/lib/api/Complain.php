<?php
namespace lib\api;

use Exception;

class Complain
{
    //获取投诉列表
    public static function list(){
        global $conf, $DB, $userrow, $queryArr;

        $paytypes = [];
        $rs = $DB->getAll("SELECT * FROM pre_type");
        foreach($rs as $row){
            $paytypes[$row['id']] = $row['name'];
        }
        unset($rs);

        $page_num = isset($queryArr['page_num']) ? intval($queryArr['page_num']) : 1;
        $page_size = isset($queryArr['page_size']) ? intval($queryArr['page_size']) : 20;
        $pid=intval($queryArr['pid']);
        $sql=" A.uid=$pid";
        if(isset($queryArr['pay_type']) && !empty($queryArr['pay_type']) && in_array($queryArr['pay_type'], $paytypes)) {
            $paytypen = array_search($queryArr['pay_type'], $paytypes);
            $sql.=" AND A.`paytype`='$paytypen'";
        }
        if(!empty($queryArr['begin_date'])){
			$starttime = daddslashes($queryArr['begin_date']);
			$sql.=" AND A.addtime>='{$starttime} 00:00:00'";
		}
		if(!empty($queryArr['end_date'])){
			$endtime = daddslashes($queryArr['end_date']);
			$sql.=" AND A.addtime<='{$endtime} 23:59:59'";
		}

        $offset = ($page_num - 1) * $page_size;
        $limit = $page_size;
        $total = $DB->getColumn("SELECT count(*) from pre_complain A WHERE{$sql}");
        $list = $DB->getAll("SELECT A.*,B.money order_money,B.name order_name,B.out_trade_no FROM pre_complain A left join pre_order B on A.trade_no=B.trade_no WHERE{$sql} order by A.addtime desc limit $offset,$limit");
        $list2 = [];
        foreach($list as $row){
            $list2[] = ['id'=>$row['id'],'source'=>$row['source'],'pay_type'=>$paytypes[$row['paytype']],'trade_no'=>$row['trade_no'],'out_trade_no'=>$row['out_trade_no'],'problem_type'=>$row['type'],'problem_description'=>$row['title'],'complaint_content'=>$row['content'],'status'=>$row['status'],'phone'=>$row['phone'],'addtime'=>$row['addtime'],'edittime'=>$row['edittime'],'order_money'=>$row['order_money'],'order_name'=>$row['order_name']];
        }

        $result = ['code'=>0, 'data'=>['total'=>$total, 'rows'=>$list2]];
        return $result;
    }

    //获取投诉详情
    public static function detail(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');
        $channel=\lib\Complain\CommUtil::getChannel($row);
        if(!$channel) throw new Exception('当前支付通道不存在');
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        if(!$model) throw new Exception('不支持该支付插件');
        $result = $model->getNewInfo($id);
        if($result['code'] == -1) throw new Exception('查询投诉详情失败：'.$result['msg']);
        $data = $result['data'];
        $data['pay_type'] = $DB->findColumn('type','name',['id'=>$data['paytype']]);
        $data['problem_type'] = $data['type'];
        $data['problem_description'] = $data['title'];
        $data['complaint_content'] = $data['content'];
        unset($data['paytype'], $data['channel'], $data['uid'], $data['thirdid'], $data['type'], $data['title'], $data['content']);

        if(!empty($data['images'])){
            foreach($data['images'] as &$image){
                if(substr($image, 0, 4) != 'http' && strpos($image, 'download.php?act=wximg') !== false)
                    $image = self::getImageUrl($image);
            }
        }
        if(!empty($data['reply_detail_infos'])){
            foreach($data['reply_detail_infos'] as &$reply_detail){
                if(!empty($reply_detail['images'])){
                    foreach($reply_detail['images'] as &$image){
                        if(substr($image, 0, 4) != 'http' && strpos($image, 'download.php?act=wximg') !== false)
                            $image = self::getImageUrl($image);
                    }
                }
            }
        }

        $order = $DB->find('order', 'trade_no,out_trade_no,money,name,buyer,status', ['trade_no'=>$row['trade_no']]);
        $data['out_trade_no'] = $order['out_trade_no'];
        $data['order_money'] = $order['money'];
        $data['order_name'] = $order['name'];

        $result = ['code'=>0, 'showtype'=>$result['showtype'], 'data'=>$data];
        return $result;
    }

    //上传图片
    public static function upload(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        if(!isset($_FILES['file']) || $_FILES['file']['size'] <= 0) throw new Exception('图片不能为空');

        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        $result = $model->uploadImage($row['thirdid'], $_FILES['file']['tmp_name'], $_FILES['file']['name']);
        return $result;
    }

    //处理投诉
    public static function feedback(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $thirdid = $row['thirdid'];
        $code = $queryArr['code'];
        $content = trim($queryArr['content']);
        $images = $queryArr['images'];
        if(empty($content) && $code !== '1') throw new Exception('必填项不能为空');
        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);

        if($row['paytype'] == 1){
            if(empty($code)) throw new Exception('必填项不能为空');
            $result = $model->feedbackSubmit($thirdid, $code, $content, $images);
        }else{
            $result = $model->replySubmit($thirdid, $content, $images);
            if($result['code'] == 0 && $queryArr['complete'] == '1'){
                $result = $model->complete($thirdid);
            }
        }
        return $result;
    }

    //回复用户
    public static function reply(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $thirdid = $row['thirdid'];
        $content = trim($queryArr['content']);
        $images = $queryArr['images'];
        if(empty($content)) throw new Exception('回复内容不能为空');
        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);

        $result = $model->replySubmit($thirdid, $content, $images);
        return $result;
    }

    //更新退款审批结果（仅微信）
    public static function refundprogress(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $thirdid = $row['thirdid'];
        $code = $queryArr['code'];
        $content = trim($queryArr['content']);
        $remark = trim($queryArr['remark']);
        $images = $queryArr['images'];
        if(empty($content) && $code === '0') throw new Exception('必填项不能为空');
        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        $result = $model->refundProgressSubmit($thirdid, $code, $content, $remark, $images);
        return $result;
    }

    //处理完成（仅微信）
    public static function complete(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $thirdid = $row['thirdid'];
        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        $result = $model->complete($thirdid);
        return $result;
    }

    //商家补充凭证（仅支付宝）
    public static function supplement(){
        global $conf, $DB, $userrow, $queryArr;

        $pid=intval($queryArr['pid']);
        $id=intval($queryArr['id']);
        $row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$pid]);
        if(!$row) throw new Exception('该投诉单不存在');

        $thirdid = $row['thirdid'];
        $content = trim($queryArr['content']);
        $images = $queryArr['images'];
        if(empty($content)) throw new Exception('凭证内容不能为空');
        $channel=\lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);

        $result = $model->supplementSubmit($thirdid, $content, $images);
        return $result;
    }

    private static function getImageUrl($url){
        global $siteurl;
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $queryArr);
        $param['channel'] = $queryArr['channel'];
        $param['mediaid'] = $queryArr['mediaid'];
        $param['time'] = time().'';
        $param['sign'] = md5($param['channel'].$param['mediaid'].$param['time'].SYS_KEY);
        return $siteurl.'api/complain/image?'.http_build_query($param);
    }

    //下载投诉图片
    public static function image(){

        if(!isset($_GET['channel']) || !isset($_GET['mediaid']) || !isset($_GET['sign']) || !isset($_GET['time'])) exit('param error');
        $channelid = intval($_GET['channel']);
        $subchannelid = intval($_GET['subchannel']);
        $media_id = $_GET['mediaid'];
        $sign = $_GET['sign'];
        $time = $_GET['time'];
        if(empty($time) || abs(time() - $_GET['time']) > 300) exit('timestamp error');
        if(md5($channelid.$media_id.$time.SYS_KEY) !== $sign) exit('sign error');

        $channel = $subchannelid ? \lib\Channel::getSub($subchannelid) : \lib\Channel::get($channelid);
        $model = \lib\Complain\CommUtil::getModel($channel);
        $image = $model->getImage($media_id);
        if($image !== false){
            $seconds_to_cache = 3600*24*7;
            header("Cache-Control: max-age=$seconds_to_cache");
            header("Content-Type: image/jpeg");
            echo $image;
        }
        exit;
    }
}