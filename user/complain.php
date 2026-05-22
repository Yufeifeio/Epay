<?php
/**
 * 支付交易投诉记录
**/
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$title='支付交易投诉记录';
include './head.php';

$type_select = '<option value="0">所有支付方式</option>';
$rs = $DB->getAll("SELECT * FROM pre_type WHERE status=1 ORDER BY id ASC");
foreach($rs as $row){
	$type_select .= '<option value="'.$row['id'].'">'.$row['showname'].'</option>';
}
unset($rs);
?>
<style>
.fixed-table-toolbar,.fixed-table-pagination{padding: 15px;}
tbody tr>td:nth-child(3){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:240px;}
tbody tr>td:nth-child(4){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:180px;}
tbody tr>td:nth-child(5){overflow: hidden;text-overflow: ellipsis;white-space: nowrap;max-width:340px;}
.dates{max-width: 120px;}
</style>
<link href="../assets/css/datepicker.css" rel="stylesheet">
<div id="content" class="app-content" role="main">
    <div class="app-content-body ">

<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">支付交易投诉</h1>
</div>
<div class="wrapper-md control">
<?php if(isset($msg)){?>
<div class="alert alert-info">
	<?php echo $msg?>
</div>
<?php }?>
<?php if(!$conf['complain_open']) showmsg('未开启交易投诉处理功能');?>
	<div class="panel panel-default">
		<div class="panel-heading font-bold">
			支付交易投诉记录
		</div>
<?php
$today = date("Y-m-d").' 00:00:00';
$yesterday = date("Y-m-d",strtotime("-1 day")).' 00:00:00';
$today_ali_count = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid='$uid' AND paytype=1 AND addtime>='$today'");
$today_wx_count = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid='$uid' AND paytype=2 AND addtime>='$today'");
$yesterday_ali_count = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid='$uid' AND paytype=1 AND addtime>='$yesterday' AND addtime<'$today'");
$yesterday_wx_count = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid='$uid' AND paytype=2 AND addtime>='$yesterday' AND addtime<'$today'");
$days7_count = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid='$uid' AND addtime>=DATE_SUB(NOW(), INTERVAL 7 DAY)");
$order_count = $DB->getColumn("SELECT count(*) from pre_order WHERE uid='$uid' and status>0 and addtime>=DATE_SUB(NOW(), INTERVAL 7 DAY)");
$complain_rate = $order_count > 0 ? round($days7_count/$order_count*100, 2) : 0;
echo '【今日投诉：<b>'.($today_ali_count+$today_wx_count).'</b> 微信投诉：<b>'.$today_wx_count.'</b> 支付宝投诉：<b>'.$today_ali_count.'</b> 】〖昨日投诉：<b>'.($yesterday_ali_count+$yesterday_wx_count).'</b> 微信投诉：<b>'.$yesterday_wx_count.'</b> 支付宝投诉：<b>'.$yesterday_ali_count.'</b> 〗投诉率(近7天)：'.$complain_rate.'%';
?>
<form onsubmit="return searchSubmit()" method="GET" class="form-inline" id="searchToolbar">
  <div class="form-group">
    <label>搜索</label>
	<select name="type" class="form-control"><option value="1">关联订单号</option><option value="2">第三方投诉单号</option><option value="3">问题类型</option><option value="4">投诉原因</option><option value="5">投诉详情</option><option value="6">联系电话</option></select>
  </div>
  <div class="form-group">
    <input type="text" class="form-control" name="kw" placeholder="搜索内容">
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
	<select name="dstatus" class="form-control"><option value="-1">全部状态</option><option value="0">待处理</option><option value="1">处理中</option><option value="2">处理完成</option></select>
  </div>
  <button type="submit" class="btn btn-primary">搜索</button>
  <a href="javascript:searchClear()" class="btn btn-default" title="刷新记录列表"><i class="fa fa-refresh"></i></a>
  <button type="button" onclick="exportOrder()" class="btn btn-default">&nbsp;导出&nbsp;</button>
</form>

      <table id="listTable">
	  </table>
    </div>
  </div>
</div>
</div>

<?php include 'foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="<?php echo $cdnpublic?>bootstrap-datepicker/1.10.0/js/bootstrap-datepicker.min.js"></script>
<script src="<?php echo $cdnpublic?>bootstrap-datepicker/1.10.0/locales/bootstrap-datepicker.zh-CN.min.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
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
		columns: [
			{
				field: 'id',
				title: 'ID',
				formatter: function(value, row, index) {
					return '<b>'+value+'</b>';
				}
			},
			{
				field: 'uid',
				title: '支付方式',
				formatter: function(value, row, index) {
					var html = row.typename ? '<img src="/assets/icon/'+row.typename+'.ico" width="16" onerror="this.style.display=\'none\'">'+row.typeshowname : '';
					if(row.subchannel > 0){
						html += '('+row.submchid+')';
					}
					return html;
				}
			},
			{
				field: 'trade_no',
				title: '关联订单号<br/>商品名称',
				formatter: function(value, row, index) {
					return '<a href="./order.php?type=1&kw='+value+'">'+value+'</a><br/>'+row.ordername;
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
					return '<a href="complain_info.php?id='+row.id+'" class="btn btn-info btn-xs">查看详情</a>';
				}
			},
		],
	});
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