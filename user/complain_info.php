<?php
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$title='投诉详情';
include './head.php';

function getOrderStatus($status){
    if($status == 1){
        return '<font color=green>已支付</font>';
    }elseif($status == 2){
        return '<font color=red>已退款</font>';
    }elseif($status == 3){
        return '<font color=red>已冻结</font>';
    }elseif($status == 4){
        return '<font color=orange>预授权</font>';
    }else{
        return '<font color=blue>未支付</font>';
    }
}
function getComplainStatus($status){
    if($status == 1){
        return '<font color=orange>处理中</font>';
    }elseif($status == 2){
        return '<font color=green>处理完成</font>';
    }else{
        return '<font color=red>待处理</font>';
    }
}
function getRoleIcon($role){
	if($role == 'user'){
		return 'fa fa-user bg-user';
	}elseif($role == 'merchat'){
		return 'fa fa-train bg-merchat';
	}elseif($role == 'system'){
		return 'fa fa-certificate bg-system';
	}
}

$my=isset($_GET['my'])?$_GET['my']:null;
?>
<style>
.img-complain{display:inline-block;overflow:hidden;margin:4px 4px 0 0;padding:5px;width:135px;height:135px;border:1px solid #ccc;border-radius:4px;background-color:#fff}
.img-complain img{width:100%;height:100%;object-fit:cover}
.img-reply{width:110px;height:110px}
button.pull-right{margin-top:-5px}
.timeline{position:relative;margin:15px 0;padding:0;list-style:none}
.timeline:before{position:absolute;top:0;bottom:0;left:31px;width:4px;border-radius:2px;background:#ddd;content:""}
.timeline>li{position:relative;margin-right:10px;margin-bottom:15px}
.timeline>li:after,.timeline>li:before{display:table;content:" "}
.timeline>li:after{clear:both}
.timeline>li>.timeline-item{position:relative;margin-top:0;margin-right:15px;margin-left:60px;padding:0;border-radius:3px;background:#fff;-webkit-box-shadow:0 1px 1px rgba(0,0,0,.1);box-shadow:0 1px 1px rgba(0,0,0,.1);color:#444}
.timeline>li>.timeline-item>.time{float:right;padding:10px;color:#777;font-size:12px}
.timeline>li>.timeline-item>.timeline-header{margin:0;padding:10px;border-bottom:1px solid #f4f4f4;color:#23527c;font-weight:600;font-size:14px}
.timeline>li>.timeline-item>.timeline-body{padding:10px}
.timeline>li>.fa{position:absolute;top:0;left:18px;width:30px;height:30px;border-radius:50%;background:#d2d6de;color:#fff;text-align:center;font-size:15px;line-height:30px}
.bg-merchat{background-color:#f39c12!important}
.bg-user{background-color:#00c0ef!important}
.bg-system{background-color:#00a65a!important}
.image-list{display:none;margin-bottom:-5px;padding:10px 0}
.image-list li{position:relative;display:block;margin-bottom:5px;padding:0 10px;height:25px;background:#f2f2f2;line-height:25px}
.image-list span{display:inline-block;margin-right:20px;vertical-align:middle}
.image-list .file-type{margin-right:5px}
.image-list .file-txt{overflow:hidden;max-width:70%;color:#666;text-overflow:ellipsis;white-space:nowrap}
.image-list a{position:absolute;top:0;right:10px;display:inline-block}
</style>

<div id="content" class="app-content" role="main">
    <div class="app-content-body ">

<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">投诉详情</h1>
</div>
<div class="wrapper-md control">
<?php if(isset($msg)){?>
<div class="alert alert-info">
	<?php echo $msg?>
</div>
<?php }?>
<div class="row">
	<div class="col-sm-12 col-md-10 col-lg-8 center-block" style="float: none;">
<?php

if(!$conf['complain_open']) showmsg('未开启交易投诉处理功能');
$id = intval($_GET['id']);
$row = $DB->find('complain', '*', ['id'=>$id, 'uid'=>$uid]);
if(!$row) showmsg('该投诉单不存在');
$channel=\lib\Complain\CommUtil::getChannel($row);
if(!$channel) showmsg('当前支付通道不存在');
$channel['source'] = $row['source'];
$channel['thirdmchid'] = $row['thirdmchid'];
try{
	$model = \lib\Complain\CommUtil::getModel($channel);
}catch(Exception $e){
	showmsg($e->getMessage());
}
if(!$model) showmsg('不支持该支付插件');
$result = $model->getNewInfo($id);
if($result['code'] == -1) showmsg('查询投诉详情失败：'.$result['msg'], 3);
$row = $result['data'];
$showtype = $result['showtype'];
$typename = $DB->findColumn('type','name',['id'=>$row['paytype']]);
$order = $DB->find('order', 'trade_no,out_trade_no,money,name,buyer,status', ['trade_no'=>$row['trade_no']]);
?>
<div class="panel panel-primary">
<div class="panel-heading"><h3 class="panel-title">投诉详情 ID:<?php echo $id?></h3></div>
<div class="panel-body">
<input type="hidden" name="id" id="id" value="<?php echo $id?>"/>
<input type="hidden" name="channel" id="channel" value="<?php echo $row['channel']?>"/>
<input type="hidden" name="trade_no" id="trade_no" value="<?php echo $row['trade_no']?>"/>
<input type="hidden" name="source" id="source" value="<?php echo $channel['source']?>"/>
<input type="hidden" name="imageType" id="imageType" value=""/>
<input type="file" id="file" onchange="fileUpload()" style="display:none;" accept="png$,jpeg$,jpg$"/>
    <div class="row">
        <div class="col-sm-12 col-md-6">
            <b>状态：</b><br/>
            <p><?php echo getComplainStatus($row['status'])?><?php if($showtype=='alipay' || $showtype=='alipayrisk'){?><font color="grey">（<?php echo $row['status_text']?>）</font><?php }?></p>
            <b>问题类型：</b><br/>
            <p><?php echo $row['type']?><?php if($showtype=='wxpay' && isset($row['apply_refund_amount']))echo '，申请退款金额：'.$row['apply_refund_amount'].'元'; ?></p>
            <?php if($showtype=='alipayrisk'){?>
			<b>投诉网站：</b><br/>
            <p><?php echo $row['complain_url']?></p>
			<?php }else{?>
            <b>投诉原因：</b><br/>
            <p><?php echo $row['title']?></p>
			<?php }?>
            <b>投诉详情：</b><br/>
            <p><?php echo $row['content']?></p>
			<?php if($showtype=='wxpay'){?>
            <b>用户投诉次数：</b><br/>
            <p><?php echo $row['user_complaint_times']?></p>
            <?php }?>
            <b>联系电话：</b><br/>
            <p><?php echo $row['phone']?></p>
        </div>
        <div class="col-sm-12 col-md-6">
            <b>商户ID：</b><br/>
            <p><a href="./ulist.php?column=uid&value=<?php echo $row['uid']?>" target="_blank"><?php echo $row['uid']?></a></p>
            <b>支付通道：</b><br/>
            <p><img src="/assets/icon/<?php echo $typename?>.ico" width="16" onerror="this.style.display=\'none\'"><?php echo $row['channel'].'__'.$channel['name']?></p>
            <b>第三方投诉单号：</b><br/>
            <p><?php echo $row['thirdid']?></p>
            <b>创建时间：</b><br/>
            <p><?php echo $row['addtime']?></p>
            <b>最后修改时间：</b><br/>
            <p><?php echo $row['edittime']?></p>
			<?php if($showtype=='wxpay'){?>
            <b>是否有待回复的用户留言：</b><br/>
            <p><?php echo $row['incoming_user_response']?'<font color="red">是</font>':'<font color="blue">否</font>';?></p>
            <?php }?>
        </div>
    </div>
    <b>投诉图片：</b><br/>
    <p><?php foreach($row['images'] as $image){ echo '<a class="img-complain" href="javascript:;" onclick="showimage(\''.$image.'\')"><img src="'.$image.'"></a>'; }?></p>

    <?php if($showtype=='alipay' && $row['status_text']=='商家处理中'){?>
    <div class="btn-group" role="group">
        <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">处理投诉<span class="caret"></span></button>
        <ul class="dropdown-menu">
            <li><a href="javascript:apirefund()">退款</a></li>
            <li><a href="javascript:feedbackForm()">其他方式处理</a></li>
        </ul>
    </div>
    <?php }?>

	<?php if($showtype=='alipayrisk' && $row['status']<2){?>
		<button type="button" class="btn btn-primary" onclick="feedbackForm()">处理投诉</button>
	<?php }?>

    <?php if($showtype=='alipay' && $row['status_text']=='平台处理中'){?>
        &nbsp;<button type="button" class="btn btn-info" onclick="supplementForm()">补充处理凭证</button>
    <?php }?>

	<?php if($showtype=='wxpay' && $row['status']<2 && $row['type']=='申请退款'){?>
    <div class="btn-group" role="group">
        <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">处理退款申请<span class="caret"></span></button>
        <ul class="dropdown-menu">
			<li><a href="javascript:refundProgressForm()">更新退款审批结果</a></li>
            <li><a href="javascript:apirefund()">退款</a></li>
        </ul>
    </div>
    <?php }?>
	
	<?php if($showtype=='wxpay' && $row['status']==1){?>
		&nbsp;<button type="button" class="btn btn-success" onclick="complete()">反馈处理完成</button>
	<?php }?>

</div>
</div>

<div class="panel panel-info">
<div class="panel-heading"><h3 class="panel-title">关联订单信息<button onclick="window.open('./order.php?type=1&kw=<?php echo $row['trade_no']?>')" class="pull-right btn btn-sm btn-info" target="_blank">查看订单</button></h3></div>
<div class="panel-body">
    <div class="row">
        <div class="col-sm-12 col-md-6">
            <b>系统订单号：</b><br/>
            <p><?php echo $row['trade_no']?></p>
            <b>商户订单号：</b><br/>
            <p><?php echo $order['out_trade_no']?></p>
            <b>商品名称：</b><br/>
            <p><?php echo $order['name']?></p>
            
        </div>
        <div class="col-sm-12 col-md-6">
            <b>订单金额：</b><br/>
            <p>¥<?php echo $order['money']?></p>
            <b>支付账号：</b><br/>
            <p><?php echo $order['buyer']?></p>
            <b>订单状态：</b><br/>
            <p><?php echo getOrderStatus($order['status'])?></p>
        </div>
    </div>
</div>
</div>

<?php if($showtype=='alipayrisk' && !empty($row['process_code'])){?>
<div class="panel panel-warning">
<div class="panel-heading"><h3 class="panel-title">商家处理进展</h3></div>
<div class="panel-body">
    <div class="row">
        <div class="col-sm-12 col-md-6">
            <b>处理时间：</b><br/>
            <p><?php echo $row['edittime']?></p>
            <b>处理备注：</b><br/>
            <p><?php echo $row['process_remark']?></p>
        </div>
        <div class="col-sm-12 col-md-6">
            <b>处理结果：</b><br/>
            <p><?php echo $row['process_message']?></p>
        </div>
    </div>
	<b>处理图片：</b><br/>
    <p><?php foreach($row['process_img_url_list'] as $image){ echo '<a class="img-complain" href="javascript:;" onclick="showimage(\''.$image.'\')"><img src="'.$image.'"></a>'; }?></p>
</div>
</div>
<?php }
if(!empty($row['reply_detail_infos'])){?>
<div class="panel panel-warning" style="background-color: #e7f4ff;">
<div class="panel-heading"><h3 class="panel-title">协商历史记录<?php if(!($showtype=='alipay' && $row['status']==2)){?><button onclick="replyForm()" class="pull-right btn btn-sm btn-primary" target="_blank">回复用户</button></h3><?php }?></div>
    <ul class="timeline">
<?php foreach($row['reply_detail_infos'] as $detail){
    echo '<li><i class="'.getRoleIcon($detail['type']).'"></i><div class="timeline-item"><span class="time"><i class="fa fa-clock-o"></i> '.$detail['time'].'</span><h3 class="timeline-header">'.$detail['name'].'</h3><div class="timeline-body">'.$detail['content'];
    if(!empty($detail['images'])){
        echo '<p>';
        foreach($detail['images'] as $image){ echo '<a class="img-complain img-reply" href="javascript:;" onclick="showimage(\''.$image.'\')"><img src="'.$image.'"></a>'; }
        echo '</p>';
    }
    echo '</div></div></li>
';
}?>
    </ul>
</div>
<?php }?>

</div>
</div>
</div>

<div class="modal" id="modal-feedback" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">Close</span></button>
				<h4 class="modal-title">处理投诉</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-feedback">
                    <div class="alert alert-info">请与用户充分协商沟通，确认投诉问题已达成和解后，在此提交处理结果。</div>
					<?php if($showtype=='alipayrisk'){?>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">处理结果</label>
						<div class="col-sm-9">
							<select name="code" class="form-control">
								<option value="CONSENSUS_WITH_CLIENT">已联系到用户，协商一致，无异议</option><option value="RECTIFICATION_NO_REFUND">不涉及退款，已针对投诉内容进行整改</option><option value="REFUND">已退款，用户无异议</option><option value="SUBMIT_PROOF_NOT_CONTACTED">已提交证明材料</option><option value="ORTHER">其他</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">处理备注</label>
						<div class="col-sm-9">
                            <textarea class="form-control" name="content" placeholder="本次投诉处理的备注信息" rows="3"></textarea>
						</div>
					</div>
					<?php }else{?>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">处理结果</label>
						<div class="col-sm-9">
							<select name="code" class="form-control">
								<option value="03">已发货</option><option value="05">已完成售后服务</option><option value="06">非我方责任范围</option><option value="02">通过其他方式退款</option><option value="04">其他</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">处理说明</label>
						<div class="col-sm-9">
                            <textarea class="form-control" name="content" placeholder="请确认已解决用户问题后，再反馈处理结果！" rows="3"></textarea>
						</div>
					</div>
					<?php }?>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">上传图片（选填）</label>
						<div class="col-sm-9">
                            <ul id="feedbackImages" class="image-list"></ul>
							<a href="javascript:uploadImage()" class="btn btn-default">添加图片</a><span class="text-muted">（最多4张，大小不超过5M）</span>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">取消</button>
				<button type="button" class="btn btn-primary" onclick="feedbackSubmit()">确定</button>
			</div>
		</div>
	</div>
</div>
<div class="modal" id="modal-reply" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">Close</span></button>
				<h4 class="modal-title">回复用户</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-reply">
					<?php if($showtype=='alipay'){?><div class="alert alert-info">留言仅供双方交流，不影响投诉进度。</div><?php }?>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">回复内容</label>
						<div class="col-sm-9">
                            <textarea class="form-control" name="content" placeholder="" rows="3"></textarea>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">上传图片（选填）</label>
						<div class="col-sm-9">
                            <ul id="replyImages" class="image-list"></ul>
							<a href="javascript:uploadImage()" class="btn btn-default">添加图片</a><span class="text-muted">（最多4张，大小不超过5M）</span>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">取消</button>
				<button type="button" class="btn btn-primary" onclick="replySubmit()">确定</button>
			</div>
		</div>
	</div>
</div>
<div class="modal" id="modal-supplement" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">Close</span></button>
				<h4 class="modal-title">补充凭证</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-supplement">
                    <div class="alert alert-info">请上传投诉处理凭证，便于客服更好的判定纠纷责任方。</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">凭证内容</label>
						<div class="col-sm-9">
                            <textarea class="form-control" name="content" placeholder="" rows="3"></textarea>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">上传图片（选填）</label>
						<div class="col-sm-9">
                            <ul id="supplementImages" class="image-list"></ul>
							<a href="javascript:uploadImage()" class="btn btn-default">添加图片</a><span class="text-muted">（最多4张，大小不超过5M）</span>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">取消</button>
				<button type="button" class="btn btn-primary" onclick="supplementSubmit()">确定</button>
			</div>
		</div>
	</div>
</div>
<div class="modal" id="modal-refund-progress" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">Close</span></button>
				<h4 class="modal-title">更新退款审批结果</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-refund-progress">
					<div class="alert alert-info">针对“申请退款单”，需要商户明确返回是否可退款的审批结果。<br/>如同意退款，需额外点击退款进行原路退款，退款到账后，投诉单的状态将自动扭转为“处理完成”。<br/>如拒绝退款，并说明拒绝退款原因，投诉单的状态将自动扭转为“处理完成”。</div>
					<div class="form-group">
						<label class="col-sm-3 control-label no-padding-right">审批动作</label>
						<div class="col-sm-9">
							<label class="radio-inline"><input type="radio" name="code" value="1" checked onchange="changeRefundAction()"> 同意退款</label>
							<label class="radio-inline"><input type="radio" name="code" value="0" onchange="changeRefundAction()"> 拒绝退款</label>
						</div>
					</div>
					<div id="form-refund-progress-reject" style="display:none">
						<div class="form-group">
							<label class="col-sm-3 control-label no-padding-right">拒绝原因</label>
							<div class="col-sm-9">
								<textarea class="form-control" name="content" placeholder="填写拒绝退款的原因" rows="3"></textarea>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label no-padding-right">备注（选填）</label>
							<div class="col-sm-9">
								<textarea class="form-control" name="remark" placeholder="任何需要向微信支付客服反馈的信息" rows="3"></textarea>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label no-padding-right">举证图片（选填）</label>
							<div class="col-sm-9">
								<ul id="refundProgressImages" class="image-list"></ul>
								<a href="javascript:uploadImage()" class="btn btn-default">添加图片</a><span class="text-muted">（最多4张，大小不超过5M）</span>
							</div>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">取消</button>
				<button type="button" class="btn btn-primary" onclick="refundProgressSubmit()">确定</button>
			</div>
		</div>
	</div>
</div>

<?php include 'foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script>
window.feedbackImages = [];
window.supplementImages = [];
window.replyImages = [];
window.refundProgressImages = [];
function showimage(resourcesUrl){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
    var img = new Image();
    img.onload = function () {//避免图片还未加载完成无法获取到图片的大小。
        //避免图片太大，导致弹出展示超出了网页显示访问，所以图片大于浏览器时下窗口可视区域时，进行等比例缩小。
        var max_height = $(window).height() - 200;
        var max_width = $(window).width();

        //rate1，rate2，rate3 三个比例中取最小的。
        var rate1 = max_height / img.height;
        var rate2 = max_width / img.width;
        var rate3 = 1;
        var rate = Math.min(rate1, rate2, rate3);
        //等比例缩放
        var imgHeight = img.height * rate; //获取图片高度
        var imgWidth = img.width * rate; //获取图片宽度

		var imgHtml = '<div id="showimg" style="width:'+imgWidth+'px; height:'+imgHeight+'px;"></div>';
		img.style = 'width:100%';
        //弹出层
		layer.close(ii);
        layer.open({
            type:1,
            shade: 0.6,
            title: false,
            area: ['auto', 'auto'],
            shadeClose: true,
            content: imgHtml,
			success: function(){
				$("#showimg").append(img)
			}
        });
    }
	img.onerror = function(){ layer.close(ii);layer.msg('图片加载错误'); }
    img.src = resourcesUrl;
}
function apirefund() {
	var trade_no = $("#trade_no").val();
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax2.php?act=refund_query',
		data : {trade_no:trade_no},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.open({
					area: ['360px'],
					title: '退款确认',
					content: '<p>此操作将直接原路退款该订单，每个订单只能操作一次退款，退款金额不能大于订单金额。</p><div class="form-group"><div class="input-group"><div class="input-group-addon">退款金额</div><input type="text" class="form-control" name="refund2" value="'+data.money+'" placeholder="请输入退款金额" autocomplete="off"/></div></div><div class="form-group"><div class="input-group"><div class="input-group-addon">登录密码</div><input type="text" class="form-control" name="paypwd" value="" placeholder="请输入用户登录密码" autocomplete="off"/></div></div>',
					yes: function(){
						var money = $("input[name='refund2']").val();
						var paypwd = $("input[name='paypwd']").val();
						if(money == '' || paypwd == ''){
							layer.alert('金额或密码不能为空');return;
						}
						var ii = layer.load(2, {shade:[0.1,'#fff']});
						$.ajax({
							type : 'POST',
							url : 'ajax2.php?act=refund_submit',
							data : {trade_no:trade_no, money:money, pwd:paypwd},
							dataType : 'json',
							success : function(data) {
								layer.close(ii);
								if(data.code == 0){
									layer.alert(data.msg, {icon:1}, function(){ layer.closeAll();searchSubmit(); });
								}else{
									layer.alert(data.msg, {icon:7});
								}
							},
							error:function(data){
								layer.close(ii);
								layer.msg('服务器错误');
							}
						});
					}
				});
			}else{
				layer.alert(data.msg, {icon:7});
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
}
function uploadImage(){
    var imageType = $("#imageType").val();
    if(imageType == '') return;
    if(window[imageType].length >= 4){
        layer.msg('最多上传4张图片', {time:700});return;
    }
    $("#file").trigger("click");
}
function fileUpload(){
	var fileObj = $("#file")[0].files[0];
	if (typeof (fileObj) == "undefined" || fileObj.size <= 0) {
		return;
	}
    var imageType = $("#imageType").val();
    if(imageType == '') return;
	var formData = new FormData();
	formData.append("id", $("#id").val());
	formData.append("file", fileObj);
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		url: "ajax_complain.php?act=uploadImage",
		data: formData,
		type: "POST",
		dataType: "json",
		cache: false,
		processData: false,
		contentType: false,
		success: function (data) {
			layer.close(ii);
			if(data.code == 0){
				layer.msg('上传图片成功', {time:800, icon:1});
				window[imageType].push(data.image_id);
                $("#"+imageType).show();
                var id = 'image'+new Date().getTime();
                $("#"+imageType).append('<li id="'+id+'" image-id="'+data.image_id+'"><span class="file-type"><i class="fa fa-picture-o"></i></span><span class="file-txt">'+fileObj.name+'</span><span class="file-status"></span><a href="javascript:deleteImage(\''+id+'\')" class="cancel">删除</a></li>');
			}else{
				layer.alert(data.msg, {icon:2});
                $("#file").val('')
			}
		},
		error:function(data){
            layer.close(ii);
			layer.msg('服务器错误');
		}
	})
}
function deleteImage(id){
    var imageType = $("#imageType").val();
    if(imageType == '') return;
    var image_id = $("#"+id).attr('image-id');
    $("#"+id).remove();
    window[imageType] = window[imageType].filter(function(item) {
        return item !== image_id
    });
}
function feedbackForm(){
	$("#modal-feedback").modal('show');
    $("#imageType").val('feedbackImages');
}
function feedbackSubmit(){
    var code = $("#form-feedback select[name='code']").val();
    var content = $("#form-feedback textarea[name='content']").val();
    if(content == ''){
        layer.alert('处理说明不能为空！');return false;
    }
    var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=feedbackSubmit',
		data : {id:$("#id").val(), code:code, content:content, images:window.feedbackImages},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert('提交投诉处理结果成功！', {
					icon: 1,
					closeBtn: false
				}, function(){
				    window.location.reload()
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
}
function replyForm(){
	$("#modal-reply").modal('show');
    $("#imageType").val('replyImages');
}
function replySubmit(){
    var content = $("#form-reply textarea[name='content']").val();
    if(content == ''){
        layer.alert('处理说明不能为空！');return false;
    }
    var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=replySubmit',
		data : {id:$("#id").val(), content:content, images:window.replyImages},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert('回复用户成功！', {
					icon: 1,
					closeBtn: false
				}, function(){
				    window.location.reload()
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
}
function supplementForm(){
	$("#modal-supplement").modal('show');
    $("#imageType").val('supplementImages');
}
function supplementSubmit(){
    var content = $("#form-supplement textarea[name='content']").val();
    if(content == ''){
        layer.alert('处理说明不能为空！');return false;
    }
    var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=supplementSubmit',
		data : {id:$("#id").val(), content:content, images:window.supplementImages},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert('提交补充凭证成功！', {
					icon: 1,
					closeBtn: false
				}, function(){
				    window.location.reload()
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
}
function refundProgressForm(){
	$("#modal-refund-progress").modal('show');
    $("#imageType").val('refundProgressImages');
}
function refundProgressSubmit(){
    var code = $("#form-refund-progress input[name='code']:checked").val();
    var content = $("#form-refund-progress textarea[name='content']").val();
	var remark = $("#form-refund-progress textarea[name='remark']").val();
    if(code == '0' && content == ''){
        layer.alert('拒绝原因不能为空！');return false;
    }
    var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=refundProgressSubmit',
		data : {id:$("#id").val(), code:code, content:content, remark:remark, images:window.refundProgressImages},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert('更新退款审批结果成功！', {
					icon: 1,
					closeBtn: false
				}, function(){
				    window.location.reload()
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
}
function changeRefundAction(){
	var code = $('input[name="code"]:checked').val();
	if(code == '1'){
		$("#form-refund-progress-reject").hide()
	}else{
		$("#form-refund-progress-reject").show()
	}
}
function complete(){
	var confirmobj = layer.confirm('请与用户协商沟通达成和解后再反馈处理完成，否则用户可能重新发起投诉。', {
	  btn: ['确定','取消'], icon:0
	}, function(){
		var ii = layer.load(2, {shade:[0.1,'#fff']});
		$.ajax({
			type : 'POST',
			url : 'ajax_complain.php?act=complete',
			data : {id:$("#id").val()},
			dataType : 'json',
			success : function(data) {
				layer.close(ii);
				if(data.code == 0){
					layer.alert('反馈处理完成成功！', {
						icon: 1,
						closeBtn: false
					}, function(){
						window.location.reload()
					});
				}else{
					layer.alert(data.msg, {icon: 2});
				}
			},
			error:function(data){
				layer.close(ii);
				layer.msg('服务器错误');
			}
		});
	}, function(){
		layer.close(confirmobj);
	});
}
</script>