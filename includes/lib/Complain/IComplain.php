<?php
namespace lib\Complain;

interface IComplain
{
    
    //刷新最新投诉记录列表
    function refreshNewList($num);

    //回调刷新单条投诉记录
    function refreshNewInfo($thirdid, $type = null);

    //获取单条投诉记录
    function getNewInfo($id);

    //上传图片
    function uploadImage($thirdid, $filepath, $filename);

    //处理投诉（仅支付宝）
    function feedbackSubmit($thirdid, $code, $content, $images = []);

    //回复用户
    function replySubmit($thirdid, $content, $images = []);

    //更新退款审批结果（仅微信）
    function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []);

    //处理完成（仅微信）
    function complete($thirdid);

    //商家补充凭证（仅支付宝）
    function supplementSubmit($thirdid, $content, $images = []);

    //下载图片（仅微信）
    function getImage($media_id);

}