<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

switch($act){
case 'list':
	$paytype = [];
	$paytypes = [];
	$rs = $DB->getAll("SELECT * FROM pre_type");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
		$paytypes[$row['id']] = $row['name'];
	}
	unset($rs);

	$sql=" 1=1";
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		$sql.=" AND A.`uid`='$uid'";
	}
	if(isset($_POST['paytype']) && !empty($_POST['paytype'])) {
		$paytypen = intval($_POST['paytype']);
		$sql.=" AND A.`paytype`='$paytypen'";
	}elseif(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$channel = intval($_POST['channel']);
		$sql.=" AND A.`channel`='$channel'";
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = intval($_POST['dstatus']);
		$sql.=" AND A.`status`={$dstatus}";
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = daddslashes($_POST['starttime']);
			$sql.=" AND A.addtime>='{$starttime} 00:00:00'";
		}
		if(!empty($_POST['endtime'])){
			$endtime = daddslashes($_POST['endtime']);
			$sql.=" AND A.addtime<='{$endtime} 23:59:59'";
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		if($_POST['column']=='title' || $_POST['column']=='content'){
			$sql.=" AND A.`{$_POST['column']}` like '%{$_POST['value']}%'";
		}else{
			$sql.=" AND A.`{$_POST['column']}`='{$_POST['value']}'";
		}
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_complain A WHERE{$sql}");
	$list = $DB->getAll("SELECT A.*,B.money ordermoney,B.name ordername,B.status orderstatus FROM pre_complain A left join pre_order B on A.trade_no=B.trade_no WHERE{$sql} order by A.addtime desc limit $offset,$limit");
	$list2 = [];
	$channelids = [];
	foreach($list as $row){
		$row['typename'] = $paytypes[$row['paytype']];
		$row['typeshowname'] = $paytype[$row['paytype']];
		if(!in_array($row['channel'], $channelids)) $channelids[] = $row['channel'];
		if(!$row['money']) $row['money'] = $row['ordermoney'];
		$list2[] = $row;
	}
	$_SESSION['complain_channels'] = $channelids;

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'getChannels':
	$plugins = \lib\Complain\CommUtil::$plugins;
	$orderby = 'id ASC';
	if(isset($_SESSION['complain_channels']) && count($_SESSION['complain_channels'])>0){
		$orderby = 'FIELD(id,'.implode(',',$_SESSION['complain_channels']).') desc,id ASC';
	}
	$list=$DB->getAll("SELECT id,name,plugin FROM pre_channel WHERE plugin IN ('".implode("','", $plugins)."') ORDER BY {$orderby}");
	$result = ['code'=>0,'msg'=>'succ','plugins'=>$plugins,'data'=>$list];
	exit(json_encode($result));
break;

case 'setNotifyUrl':
	$action = intval($_POST['action']);
	$channelid = intval($_POST['channel']);
	$channel=\lib\Channel::get($channelid);
	if(!$channel)exit('{"code":-1,"msg":"当前支付通道不存在！"}');
	try{
		$model = \lib\Complain\CommUtil::getModel($channel);
		if(!$model)exit('{"code":-1,"msg":"不支持该支付插件"}');
		if($action == 1){
			$result = $model->setNotifyUrl();
		}else{
			$result = $model->delNotifyUrl();
		}
		exit(json_encode($result));
	}catch(Exception $e){
		exit('{"code":-1,"msg":"'.$e->getMessage().'"}');
	}
break;

case 'refreshNewList':
	$channelid = intval($_POST['channel']);
	$subchannelid = intval($_POST['subchannel']);
	$num = intval($_POST['num']);
	$source = isset($_POST['source'])?intval($_POST['source']):0;
	if($num < 10) $num = 10;
	$channel=$subchannelid>0 ? \lib\Channel::getSub($subchannelid) : \lib\Channel::get($channelid);
	if(!$channel)exit('{"code":-1,"msg":"当前支付通道不存在！"}');
	$channel['source'] = $source;
	try{
		$model = \lib\Complain\CommUtil::getModel($channel);
		if(!$model)exit('{"code":-1,"msg":"不支持该支付插件"}');
		$result = $model->refreshNewList($num);
		exit(json_encode($result));
	}catch(Exception $e){
		exit('{"code":-1,"msg":"'.$e->getMessage().'"}');
	}
break;

case 'uploadImage':
	if(!isset($_FILES['file']))exit('{"code":-1,"msg":"请选择图片"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->uploadImage($row['thirdid'], $_FILES['file']['tmp_name'], $_FILES['file']['name']);
	exit(json_encode($result));
break;

case 'feedbackSubmit':
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$code = $_POST['code'];
	$content = trim($_POST['content']);
	$images = $_POST['images'];
	if(empty($code) || empty($content) && $code !== '1')exit('{"code":-1,"msg":"必填项不能为空"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->feedbackSubmit($thirdid, $code, $content, $images);
	exit(json_encode($result));
break;

case 'replySubmit':
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$content = trim($_POST['content']);
	$images = $_POST['images'];
	if(empty($content))exit('{"code":-1,"msg":"必填项不能为空"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->replySubmit($thirdid, $content, $images);
	exit(json_encode($result));
break;

case 'supplementSubmit':
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$content = trim($_POST['content']);
	$images = $_POST['images'];
	if(empty($content))exit('{"code":-1,"msg":"必填项不能为空"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->supplementSubmit($thirdid, $content, $images);
	exit(json_encode($result));
break;

case 'refundProgressSubmit':
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$code = $_POST['code'];
	$content = trim($_POST['content']);
	$remark = trim($_POST['remark']);
	$images = $_POST['images'];
	if(empty($content) && $code === '0')exit('{"code":-1,"msg":"必填项不能为空"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->refundProgressSubmit($thirdid, $code, $content, $remark, $images);
	exit(json_encode($result));
break;

case 'complete':
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->complete($thirdid);
	exit(json_encode($result));
break;

case 'delComplain':
	$id = intval($_GET['id']);
	if($DB->exec("DELETE FROM pre_complain WHERE id='$id'")!==false)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"删除失败['.$DB->error().']"}');
break;

case 'operation':
	$status=is_numeric($_POST['status'])?intval($_POST['status']):exit('{"code":-1,"msg":"请选择操作"}');
	$checkbox=$_POST['checkbox'];
	$i=0;
	foreach($checkbox as $id){
		if($status==1)$DB->exec("DELETE FROM pre_complain WHERE id='$id'");
		if($status==2){

		}
		$i++;
	}
	exit('{"code":0,"msg":"成功删除'.$i.'条记录"}');
break;

case 'batch_reply':
	$content = isset($_POST['content']) ? trim($_POST['content']) : '';
	if(empty($content)) exit('{"code":-1,"msg":"回复内容不能为空"}');
    $checkbox=$_POST['checkbox'];

    $results = []; // 用于存储每个投诉记录的处理结果
    $successCount = 0; // 成功处理的投诉记录数量
	$conf['complain_auto_reply'] = 1;

    foreach ($checkbox as $id) {
        $id = intval($id);
        $row = $DB->find('complain', '*', ['id' => $id]);
        if (!$row) {
            $results[] = ['code' => -1, 'msg' => '投诉记录不存在', 'id' => $id];
            continue;
        }

        $thirdid = $row['thirdid'];
        $channel = \lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        $result = $model->feedbackSubmit($thirdid, null, $content);

        if ($result['code'] === 0) {
            $successCount++;
        }
        $results[] = $result;
    }
    
    exit('{"code":0,"msg":"成功处理了'.$successCount.'条投诉"}');
break;

case 'batch_complete':
    $checkbox=$_POST['checkbox'];

    $results = []; // 用于存储每个投诉记录的处理结果
    $successCount = 0; // 成功处理的投诉记录数量
	$conf['complain_auto_reply'] = 1;

    foreach ($checkbox as $id) {
        $id = intval($id);
        $row = $DB->find('complain', '*', ['id' => $id]);
        if (!$row) {
            $results[] = ['code' => -1, 'msg' => '投诉记录不存在', 'id' => $id];
            continue;
        }
		if($row['status'] != 1) continue;

        $thirdid = $row['thirdid'];
        $channel = \lib\Complain\CommUtil::getChannel($row);
        $channel['source'] = $row['source'];
        $channel['thirdmchid'] = $row['thirdmchid'];
        $model = \lib\Complain\CommUtil::getModel($channel);
        $result = $model->complete($thirdid);

        if ($result['code'] === 0) {
            $successCount++;
        }
        $results[] = $result;
    }
    
    exit('{"code":0,"msg":"成功将'.$successCount.'条投诉改为已完成"}');
break;

case 'charts_list':
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$date = date("Y-m-d");
	$date1 = date("Y-m-d", strtotime("-1 day"));
	$count = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date' group by uid");
	$count1 = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date1' and addtime<'$date' group by uid");
	$alipay = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date' and paytype=1 group by uid");
	$alipay1 = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date1' and addtime<'$date' and paytype=1 group by uid");
	$wxpay = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date' and paytype=2 group by uid");
	$wxpay1 = $DB->getAll("SELECT uid,count(*) as count from pre_complain WHERE addtime>='$date1' and addtime<'$date' and paytype=2 group by uid");
	$list = [];
	// 查询所有商户的列表
	$merchants = $DB->getAll("SELECT uid FROM pre_complain GROUP BY uid");

	// 初始化$list数组
	$list = [];
	foreach ($merchants as $merchant) {
		$uid = $merchant['uid'];
		$list[$uid] = [
			'uid' => $uid,
			'connt' => 0, // 初始化今日投诉数量为0
			'alipay' => 0, // 初始化今日支付宝支付的投诉数量为0
			'wxpay' => 0, // 初始化今日微信支付的投诉数量为0
			'connt1' => 0, // 初始化昨日投诉数量为0
			'alipay1' => 0, // 初始化昨日支付宝支付的投诉数量为0
			'wxpay1' => 0 // 初始化昨日微信支付的投诉数量为0
		];
	}

	// 更新$list数组中的投诉数量
	foreach ($count as $row) {
		$list[$row['uid']]['connt'] = $row['count'];
	}
	foreach ($alipay as $row) {
		$list[$row['uid']]['alipay'] = $row['count'];
	}
	foreach ($wxpay as $row) {
		$list[$row['uid']]['wxpay'] = $row['count'];
	}
	foreach ($count1 as $row) {
		$list[$row['uid']]['connt1'] = $row['count'];
	}
	foreach ($alipay1 as $row) {
		$list[$row['uid']]['alipay1'] = $row['count'];
	}
	foreach ($wxpay1 as $row) {
		$list[$row['uid']]['wxpay1'] = $row['count'];
	}

	// 过滤掉今日和昨日投诉数量都为0的商户
	$filteredList = array_filter($list, function ($item) {
		return $item['connt'] > 0 || $item['connt1'] > 0;
	});

	// 对$list数组进行排序
	$sort = [];
	foreach ($filteredList as $key => $value) {
		$sort[] = $value['connt'];
	}
	array_multisort($sort, SORT_DESC, $filteredList);

	// 返回结果
	$result = ['total' => count($filteredList), 'rows' => array_slice($filteredList, $offset, $limit)];
	exit(json_encode($result));
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}