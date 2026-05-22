<?php
include("../includes/common.php");
if($islogin2==1){}else exit('{"code":-3,"msg":"No Login"}');
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

	$sql=" A.uid=$uid";
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
	if(isset($_POST['kw']) && !empty($_POST['kw'])) {
		$kw=daddslashes($_POST['kw']);
		if($_POST['type']==1){
			$sql.=" AND A.`trade_no`='{$kw}'";
		}elseif($_POST['type']==2){
			$sql.=" AND A.`thirdid`='{$kw}'";
		}elseif($_POST['type']==3){
			$sql.=" AND A.`type`='{$kw}'";
		}elseif($_POST['type']==4){
			$sql.=" AND A.`title` like '%{$kw}%'";
		}elseif($_POST['type']==5){
			$sql.=" AND A.`content` like '%{$kw}%'";
		}elseif($_POST['type']==6){
			$sql.=" AND A.`phone`='{$kw}'";
		}
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_complain A WHERE{$sql}");
	$list = $DB->getAll("SELECT A.*,B.money ordermoney,B.name ordername,C.apply_id submchid FROM pre_complain A LEFT JOIN pre_order B ON A.trade_no=B.trade_no LEFT JOIN pre_subchannel C ON A.subchannel=C.id WHERE{$sql} order by A.addtime desc limit $offset,$limit");
	$list2 = [];
	foreach($list as $row){
		$row['typename'] = $paytypes[$row['paytype']];
		$row['typeshowname'] = $paytype[$row['paytype']];
		if(!$row['money']) $row['money'] = $row['ordermoney'];
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;
case 'uploadImage':
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	if(!isset($_FILES['file']))exit('{"code":-1,"msg":"请选择图片"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->uploadImage($row['thirdid'], $_FILES['file']['tmp_name'], $_FILES['file']['name']);
	exit(json_encode($result));
break;
case 'feedbackSubmit':
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
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
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
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
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
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
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
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
	if(!$conf['complain_open'])exit('{"code":-1,"msg":"未开启交易投诉处理功能"}');
	$id = intval($_POST['id']);
	$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
	if(!$row)exit('{"code":-1,"msg":"投诉记录不存在"}');
	$thirdid = $row['thirdid'];
	$channel=\lib\Complain\CommUtil::getChannel($row);
	$channel['source'] = $row['source'];
	$channel['thirdmchid'] = $row['thirdmchid'];
	$model = \lib\Complain\CommUtil::getModel($channel);
	$result = $model->complete($thirdid);
	exit(json_encode($result));
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}