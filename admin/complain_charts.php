<?php

/**
 * 支付交易投诉记录排行榜
 **/
include("../includes/common.php");
$title = '投诉排行';
include './head.php';

// 检查用户是否登录
if ($islogin != 1) {
    exit("<script language='javascript'>window.location.href='./login.php';</script>");
}

$date = date("Y-m-d");
$date1 = date("Y-m-d", strtotime("-1 day"));

?>

<!-- 添加CSS样式来美化表格 -->
<style>
.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.table th {
    background-color: #f2f2f2;
}
</style>

<div class="container" style="padding-top:70px;">
    <div class="col-md-12 center-block" style="float: none;">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-bars"></i>&nbsp;&nbsp;<b>投诉数据统计&排行榜</b></h2></div>
            <div class="form-group">
                <div class="input-group">
                    <span class="input-group-addon">今日投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date'"); ?>"
                        disabled>
                    <span class="input-group-addon">支付宝投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date' and paytype=1"); ?>"
                        disabled>
                    <span class="input-group-addon">微信投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date' and paytype=2"); ?>"
                        disabled>
                </div>
            </div>
            <div class="form-group">
                <div class="input-group">
                    <span class="input-group-addon">昨日投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date1' and addtime<'$date'"); ?>"
                        disabled>
                    <span class="input-group-addon">支付宝投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date1' and addtime<'$date' and paytype=1"); ?>"
                        disabled>
                    <span class="input-group-addon">微信投诉总条数</span>
                    <input type="text" class="form-control"
                        value="<?php echo $DB->getColumn("SELECT count(*) from pre_complain WHERE addtime>='$date1' and addtime<'$date' and paytype=2"); ?>"
                        disabled>
                </div>
            </div>
            <div class="table-responsive">
                <table id="charts_list">

                </table>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
$(document).ready(function() {
    updateToolbar();
    const defaultPageSize = 30;
    const pageNumber = typeof window.$_GET['pageNumber'] != 'undefined' ? parseInt(window.$_GET['pageNumber']) :
        1;
    const pageSize = typeof window.$_GET['pageSize'] != 'undefined' ? parseInt(window.$_GET['pageSize']) :
        defaultPageSize;
    $('#charts_list').bootstrapTable({
        url: 'ajax_complain.php?act=charts_list',
        pageNumber: pageNumber,
        pageSize: pageSize,
        classes: 'table table-striped table-hover table-bordered',
        showColumns: false,
        showFullscreen: false,
        columns: [{
                field: 'uid',
                title: '商户ID',
                align: 'center'
            },
            {
                field: 'connt',
                title: '<span style="color:red";>今日投诉总条数</span>',
                align: 'center'
            },
            {
                field: 'alipay',
                title: '<span style="color:red";>今日支付宝投诉总条数</span>',
                align: 'center'
            },
            {
                field: 'wxpay',
                title: '<span style="color:red";>今日微信投诉总条数</span>',
                align: 'center'
            },
            {
                field: 'connt1',
                title: '昨日投诉总条数',
                align: 'center'
            },
            {
                field: 'alipay1',
                title: '昨日支付宝投诉总条数',
                align: 'center'
            },
            {
                field: 'wxpay1',
                title: '昨日微信投诉总条数',
                align: 'center'
            }
        ]
    });
});
</script>