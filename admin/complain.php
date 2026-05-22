<?php
/**
 * 支付交易投诉记录
**/
include("../includes/common.php");
$title='支付交易投诉记录';
include './head.php';
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

addon_update('complain', '1003');

$type_select = '<option value="0">所有方式</option>';
$rs = $DB->getAll("SELECT * FROM pre_type ORDER BY id ASC");
foreach($rs as $row){
	$type_select .= '<option value="'.$row['id'].'">'.$row['showname'].'</option>';
}
unset($rs);
?>
<style>
tbody tr>td:nth-child(5){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:220px;}
tbody tr>td:nth-child(6){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:180px;}
tbody tr>td:nth-child(7){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:330px;}
.list-group-item{word-break: break-all;}
.dates{max-width: 120px;}
</style>
<link href="../assets/css/datepicker.css" rel="stylesheet">
<div class="modal" id="modal-store" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content animated flipInX">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">Close</span></button>
				<h4 class="modal-title" id="modal-title">获取最新投诉记录</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-store">
					<div class="form-group">
						<label class="col-sm-2 control-label">支付通道</label>
						<div class="col-sm-10">
							<select name="channel" id="channel" class="form-control" onchange="changeChannel()">
							</select>
							<font id="channel_tips" color="green"></font>
						</div>
					</div>
					<div class="form-group" id="subchannel_div" style="display:none">
						<label class="col-sm-2 control-label no-padding-right">子通道</label>
						<div class="col-sm-10">
							<select name="subchannel" id="subchannel" class="form-control"></select>
						</div>
					</div>
					<div class="form-group" id="form_source_alipay" style="display:none">
						<label class="col-sm-2 control-label">获取方式</label>
						<div class="col-sm-10">
							<select name="source" id="source" class="form-control" onchange="changeChannel()">
							<option value="1">RiskGO消费者投诉</option><option value="0">小程序交易投诉处理</option>
							</select>
						</div>
					</div>
					<hr/>
					<div class="form-group" id="form_getnewlist" style="display:none">
						<label class="col-sm-2 control-label">手动获取</label>
						<div class="col-sm-10">
						<div class="input-group"><div class="input-group-addon">获取最新</div>
				<input type="number" name="num" id="get_num" value="20" class="form-control" required placeholder="条数"/>
				<div class="input-group-addon">条投诉记录</div><div class="input-group-btn"><a href="javascript:refreshNewList()" class="btn btn-info">立即获取</a></div></div>
						</div>
					</div>
					<hr/>
					<div class="form-group" id="form_notify_alipay" style="display:none">
						<label class="col-sm-2 control-label">自动获取</label>
						<div class="col-sm-10">
							在支付宝开放平台应用订阅“交易投诉通知回调”，<br/>应用网关地址：<?php echo $siteurl?>pay/appgw/<span class="channelid">0</span>/<hr/><font color="green">注：只能获取到用户在【账单详情->对此订单有疑问->交易投诉】入口提交的投诉（大多数商户无此入口），无法获取支付宝举报中心提交的投诉，对应支付宝后台【小程序与代扣等投诉】列表。</font>
						</div>
					</div>
					<div class="form-group" id="form_notify_alipayrisk" style="display:none">
						<label class="col-sm-2 control-label">自动获取</label>
						<div class="col-sm-10">
							定时访问以下网址：<li class="list-group-item"><?php echo $siteurl?>cron.php?do=complain&channel=<span class="channelid">0</span>&key=<?php echo $conf['cronkey']; ?></li><br/><font color="green">注：需签约RiskGO，可获取支付宝举报中心提交的投诉，对应支付宝后台【支付交易投诉】列表。</font>
						</div>
					</div>
					<div class="form-group" id="form_notify_wxpay" style="display:none">
						<label class="col-sm-2 control-label">自动获取</label>
						<div class="col-sm-10">
						<a href="javascript:setnotifyurl(1)" class="btn btn-success btn-block">设置投诉通知回调地址</a><br/>
						<a href="javascript:setnotifyurl(0)" class="btn btn-warning btn-block">删除投诉通知回调地址</a><br/><font color="green">注：开启后微信商户的投诉将自动回调到本站，如本站更换域名，需要重新点击设置按钮。</font>
						</div>
					</div>
					<div class="form-group" id="form_notify_other" style="display:none">
						<label class="col-sm-2 control-label">自动获取</label>
						<div class="col-sm-10">
							回调地址：<?php echo $siteurl?>pay/complainnotify/<span class="channelid">0</span>/
						</div>
					</div>
					<div class="form-group" id="form_notify_cron" style="display:none">
						<label class="col-sm-2 control-label">自动获取</label>
						<div class="col-sm-10">
							定时访问以下网址：<li class="list-group-item"><?php echo $siteurl?>cron.php?do=complain&channel=<span class="channelid">0</span>&key=<?php echo $conf['cronkey']; ?></li>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">关闭</button>
			</div>
		</div>
	</div>
</div>
<div class="modal" id="modal-reply" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true"
    data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content animated flipInX">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span
                        class="sr-only">Close</span></button>
                <h4 class="modal-title" id="modal-title">批量处理投诉</h4>
            </div>
            <div class="modal-body">
                <form class="form-horizontal" id="form-reply">
                    <div class="form-group mb0">
                        <label class="col-sm-2 control-label">回复内容</label>
                        <div class="col-sm-10">
                            <textarea class="form-control" name="content" id="content" rows="5"
                                placeholder="请输入回复内容"></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="checkbox" id="checkbox" value="">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-white" data-dismiss="modal">关闭</button>
                <button type="button" class="btn btn-primary" onclick="batchReply()">提交</button>
            </div>
        </div>
    </div>
</div>
  <div class="container" style="padding-top:70px;">
	<div class="row">
    <div class="col-md-12 center-block" style="float: none;">
<a style="color:red;font-weight:bold" href="./complain_charts.php">查看投诉数据统计&排行榜</a>
<form onsubmit="return searchSubmit()" method="GET" class="form-inline" id="searchToolbar">
  <div class="form-group">
    <label>搜索</label>
	<select name="column" class="form-control"><option value="trade_no">关联订单号</option><option value="thirdid">第三方单号</option><option value="type">问题类型</option><option value="title">投诉原因</option><option value="content">投诉详情</option><option value="phone">联系电话</option></select>
  </div>
  <div class="form-group">
    <input type="text" class="form-control" name="value" placeholder="搜索内容">
  </div>
  <div class="form-group">
    <input type="text" class="form-control" name="uid" style="width: 100px;" placeholder="商户号" value="">
  </div>
  <div class="input-group input-daterange">
	<input type="text" id="starttime" name="starttime" class="form-control dates" placeholder="开始日期" autocomplete="off" title="留空则不限时间范围">
	<span class="input-group-addon" onclick="$('#starttime').val('');$('#endtime').val('');" title="清除"><i class="fa fa-chevron-right"></i></span>
	<input type="text" id="endtime" name="endtime" class="form-control dates" placeholder="结束日期" autocomplete="off" title="留空则不限时间范围">
  </div>
  <div class="form-group">
    <select name="paytype" class="form-control"><?php echo $type_select?></select>
  </div>
  <div class="form-group">
    <input type="text" class="form-control" name="channel" style="width: 80px;" placeholder="通道ID" value="">
  </div>
  <div class="form-group">
	<select name="dstatus" class="form-control"><option value="-1">全部状态</option><option value="0">待处理</option><option value="1">处理中</option><option value="2">处理完成</option></select>
  </div>
  <button type="submit" class="btn btn-primary">搜索</button>
  <a href="javascript:searchClear()" class="btn btn-default" title="刷新记录列表"><i class="fa fa-refresh"></i></a>
  <div class="btn-group" role="group">
	<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">批量操作 <span class="caret"></span></button>
	<ul class="dropdown-menu"><li><a href="javascript:operation(1)">批量删除</a></li><li><a href="javascript:batchFeedback()">批量处理投诉</a></li><li><a href="javascript:batchComplete()">批量处理完成</a></li></ul>
  </div>
  <a href="javascript:exportOrder()" class="btn btn-default"> 导出 </a>
  <a href="javascript:getdialog()" class="btn btn-success">获取最新数据</a>
</form>
      <table id="listTable">
	  </table>
    </div>
  </div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="<?php echo $cdnpublic?>bootstrap-datepicker/1.10.0/js/bootstrap-datepicker.min.js"></script>
<script src="<?php echo $cdnpublic?>bootstrap-datepicker/1.10.0/locales/bootstrap-datepicker.zh-CN.min.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
var plugins_notify = <?php echo json_encode(\lib\Complain\CommUtil::$plugins_notify); ?>;
$(document).ready(function(){
	updateToolbar();
	const defaultPageSize = 10;
	const pageNumber = typeof window.$_GET['pageNumber'] != 'undefined' ? parseInt(window.$_GET['pageNumber']) : 1;
	const pageSize = typeof window.$_GET['pageSize'] != 'undefined' ? parseInt(window.$_GET['pageSize']) : defaultPageSize;

	$("#listTable").bootstrapTable({
		url: 'ajax_complain.php?act=list',
		pageNumber: pageNumber,
		pageSize: pageSize,
		classes: 'table table-striped table-hover table-bordered',
		showFullscreen: false,
		showColumns: false,
		columns: [
			{
				field: '',
				checkbox: true
			},
			{
				field: 'id',
				title: 'ID',
				formatter: function(value, row, index) {
					return '<b>'+value+'</b>';
				}
			},
			{
				field: 'uid',
				title: '商户号',
				formatter: function(value, row, index) {
					return '<b><a href="./ulist.php?column=uid&value='+value+'" target="_blank">'+value+'</a></b>';
				}
			},
			{
				field: 'channel',
				title: '通道ID',
				formatter: function(value, row, index) {
					return row.typename ? '<img src="/assets/icon/'+row.typename+'.ico" width="16" onerror="this.style.display=\'none\'"><a href="./pay_channel.php?kw='+value+'&type=0&dstatus=-1" target="_blank">'+value+'</a>' : '';
				}
			},
			{
				field: 'trade_no',
				title: '关联订单号<br/>商品名称',
				formatter: function(value, row, index) {
					return '<a href="./order.php?column=trade_no&value='+value+'" target="_blank">'+value+'</a><br/>'+row.ordername;
				}
			},
			{
				field: 'type',
				title: '问题类型<br/>订单金额',
				formatter: function(value, row, index) {
					return value+'<br/>¥'+row.money;
				}
			},
			{
				field: 'title',
				title: '投诉原因<br/>投诉详情',
				formatter: function(value, row, index) {
					return value+'<br/>'+row.content;
				}
			},
			{
				field: 'addtime',
				title: '创建时间<br/>最后更新时间',
				formatter: function(value, row, index) {
					return value+'<br/>'+row.edittime;
				}
			},
			{
				field: 'status',
				title: '状态',
				formatter: function(value, row, index) {
					if(value == '1'){
						return '<font color=orange>处理中</font>';
					}else if(value == '2'){
						return '<font color=green>处理完成</font>';
					}else{
						return '<font color=red>待处理</font>';
					}
				}
			},
			{
				field: 'status',
				title: '操作',
				formatter: function(value, row, index) {
					return '<a href="complain_info.php?id='+row.id+'" class="btn btn-info btn-xs">详情</a>&nbsp;<a href="javascript:delItem('+row.id+')" class="btn btn-danger btn-xs">删除</a>';
				}
			},
		],
	})
})
function delItem(id) {
	var confirmobj = layer.confirm('你确实要删除此记录吗？', {
	  btn: ['确定','取消'], icon:0
	}, function(){
		$.ajax({
			type : 'GET',
			url : 'ajax_complain.php?act=delComplain&id='+id,
			dataType : 'json',
			success : function(data) {
				if(data.code == 0){
					layer.closeAll();
					searchSubmit();
				}else{
					layer.alert(data.msg, {icon: 2});
				}
			},
			error:function(data){
				layer.msg('服务器错误');
				return false;
			}
		});
	}, function(){
		layer.close(confirmobj);
	});
}
function getdialog(){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'GET',
		url : 'ajax_complain.php?act=getChannels',
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				$("#modal-store").modal('show');
				$("#channel").empty();
				$("#channel").append('<option value="0">请选择支付通道</option>');
				$.each(data.data, function(index, item){
					$("#channel").append('<option value="'+item.id+'" plugin="'+item.plugin+'">'+item.id+'__'+item.name+'</option>');
				});
				$("#channel_tips").text('只支持'+data.plugins.join('、')+'支付插件');
				changeChannel();
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
function changeChannel(){
	var existSubChannel = ['alipaysl','dinpay','huolian','kuaiqian','fuiou2','allinpay','helipay'];
	var channel = parseInt($("#channel").val());
	var source = parseInt($("#source").val());
	$("#subchannel_div").hide();
	$("#subchannel").empty();
	if(channel>0){
		$("#form_getnewlist").show();
		$(".channelid").text(channel)
		var plugin = $("#channel option:selected").attr('plugin');
		if(existSubChannel.indexOf(plugin)>-1){
			$("#subchannel_div").show();
			getSubChannels();
		}
		if(plugin.indexOf('wxpay')>-1 || plugin.indexOf('huolian')>-1 || plugin.indexOf('shengpay')>-1){
			$("#form_notify_alipay").hide();
			$("#form_notify_alipayrisk").hide();
			$("#form_notify_wxpay").show();
			$("#form_notify_cron").hide();
			$("#form_notify_other").hide();
			$("#form_source_alipay").hide();
		}
		else if(plugin.indexOf('alipay')>-1){
			if(source == 1){
				$("#form_notify_alipay").hide();
				$("#form_notify_alipayrisk").show();
			}else{
				$("#form_notify_alipay").show();
				$("#form_notify_alipayrisk").hide();
			}
			$("#form_notify_wxpay").hide();
			$("#form_notify_cron").hide();
			$("#form_notify_other").hide();
			$("#form_source_alipay").show();
		}
		else if(plugins_notify.indexOf(plugin)>-1){
			$("#form_notify_alipay").hide();
			$("#form_notify_alipayrisk").hide();
			$("#form_notify_wxpay").hide();
			$("#form_notify_cron").hide();
			$("#form_notify_other").show();
			$("#form_source_alipay").hide();
		}
		else{
			$("#form_notify_alipay").hide();
			$("#form_notify_alipayrisk").hide();
			$("#form_notify_wxpay").hide();
			$("#form_notify_cron").show();
			$("#form_notify_other").hide();
			$("#form_source_alipay").hide();
		}
	}else{
		$("#form_getnewlist").hide();
		$("#form_notify_alipay").hide();
		$("#form_notify_alipayrisk").hide();
		$("#form_notify_wxpay").hide();
		$("#form_source_alipay").hide();
	}
}
function refreshNewList(){
	var channel = parseInt($("#channel").val());
	if(channel == 0){
		layer.alert('请选择支付通道！');return false;
	}
	var source = parseInt($("#source").val());
	var num = parseInt($("#get_num").val());
	var subchannel = $("#subchannel").val();
	if(num < 10) num = 10;
	if(num > 1000) {
		layer.alert('最多获取1000条投诉记录');return false;
	}
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=refreshNewList',
		data : {channel:channel, subchannel:subchannel, num:num, source:source},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert(data.msg,{
					icon: 1,
					closeBtn: false
				}, function(){
					layer.closeAll();
					$("#modal-store").modal('hide');
					searchSubmit();
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
function setnotifyurl(action){
	var channel = parseInt($("#channel").val());
	if(channel == 0){
		layer.alert('请选择支付通道！');return false;
	}
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=setNotifyUrl',
		data : {channel:channel, action:action},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert(action==1?'设置投诉通知回调地址成功！':'删除投诉通知回调地址成功！', {icon: 1})
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
function operation(status){
	var selected = $('#listTable').bootstrapTable('getSelections');
	if(selected.length == 0){
		layer.msg('未选择记录', {time:1500});return;
	}
	if(status == 1 && !confirm('确定要删除已选中的'+selected.length+'个记录吗？')) return;
	var checkbox = new Array();
	$.each(selected, function(key, item){
		checkbox.push(item.id)
	})
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_complain.php?act=operation',
		data : {status:status, checkbox:checkbox},
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				searchSubmit();
				layer.alert(data.msg);
			}else{
				layer.alert(data.msg);
			}
		},
		error:function(data){
			layer.close(ii);
			layer.msg('请求超时');
		}
	});
	return false;
}
function batchFeedback(){
	var selected = $('#listTable').bootstrapTable('getSelections');
	if(selected.length == 0){
		layer.msg('未选择记录', {time:1500});return;
	}
	$('#modal-reply').modal('show')
}
function batchReply() {
	var selected = $('#listTable').bootstrapTable('getSelections');
	if(selected.length == 0){
		layer.msg('未选择记录', {time:1500});return;
	}
    var content = $("#form-reply textarea[name='content']").val();
    var checkbox = new Array();
    $.each(selected, function(key, item) {
        checkbox.push(item.id)
    })
    var ii = layer.load(2, {shade:[0.1,'#fff']});
    $.ajax({
        type: 'POST',
        url: 'ajax_complain.php?act=batch_reply',
        data: {content: content, checkbox: checkbox},
        dataType: 'json',
        success: function(data) {
            layer.close(ii);
            if (data.code == 0) {
                layer.alert(data.msg, {icon: 1}, function() {
                    layer.closeAll();
                    $("#modal-reply").modal('hide');
                    searchSubmit();
                });
            } else {
                layer.alert(data.msg, {icon: 2})
            }
        },
        error: function(data) {
            layer.close(ii);
            layer.msg('服务器错误');
        }
    });
}
function batchComplete() {
	var selected = $('#listTable').bootstrapTable('getSelections');
	if(selected.length == 0){
		layer.msg('未选择记录', {time:1500});return;
	}
	if(!confirm('该操作只支持将处理中的微信投诉改成已完成')) return;
    var checkbox = new Array();
    $.each(selected, function(key, item) {
        checkbox.push(item.id)
    })
    var ii = layer.load(2, {shade:[0.1,'#fff']});
    $.ajax({
        type: 'POST',
        url: 'ajax_complain.php?act=batch_complete',
        data: {checkbox: checkbox},
        dataType: 'json',
        success: function(data) {
            layer.close(ii);
            if (data.code == 0) {
                layer.alert(data.msg, {icon: 1}, function() {
                    layer.closeAll();
                    $("#modal-reply").modal('hide');
                    searchSubmit();
                });
            } else {
                layer.alert(data.msg, {icon: 2})
            }
        },
        error: function(data) {
            layer.close(ii);
            layer.msg('服务器错误');
        }
    });
}
function getSubChannels(subchannel){
	subchannel = subchannel || null;
	var uid = $("#uid").val();
	var channel = $("#channel").val();
	$("#subchannel").empty();
	if(uid == '' || channel == '0') return;
	$.ajax({
		type : 'GET',
		url : 'ajax_pay.php?act=getSubChannels&channel='+channel,
		dataType : 'json',
		success : function(data) {
			if(data.code == 0){
				$("#subchannel").append('<option value="0">--通道未使用变量模式可留空--</option>');
				$.each(data.data, function (i, res) {
					$("#subchannel").append('<option value="'+res.id+'">'+res.id+'__'+res.name+'</option>');
				})
				if(subchannel!=null)$("#subchannel").val(subchannel);
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.msg('服务器错误');
			return false;
		}
	});
}
$(document).ready(function(){
	$('.input-datepicker, .input-daterange').datepicker({
        format: 'yyyy-mm-dd',
		autoclose: true,
        clearBtn: true,
        language: 'zh-CN'
    });
})
function exportOrder(){
	var params = {};
	$('#searchToolbar').find(':input[name]').each(function() {
		params[$(this).attr('name')] = $(this).val()
	})
	window.location.href='./download.php?act=complain&'+$.param(params);
}
</script>