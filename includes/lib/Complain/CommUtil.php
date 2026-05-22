<?php
namespace lib\Complain;

class CommUtil
{
    public static $plugins = ['alipay','alipaysl','alipayd','wxpayn','wxpaynp','huifu','kuaiqian','huolian','yeepay','epayn','dinpay','fuiou','fuiou2','passpay','allinpay','haipay','hnapay','heepay','hwkjpay','fubei','yinyingtong','shengpay','timezsoft','xhdpay','lakalamoss','helipay','sumpay','chinaso'];
    public static $plugins_notify = ['kuaiqian','dinpay','fuiou2','haipay','yinyingtong','kunpeng','helipay','sumpay'];

    public static function getModel($channel){
        if($channel['plugin'] == 'alipay' || $channel['plugin'] == 'alipaysl' || $channel['plugin'] == 'alipayd'){
            if($channel['source'] == 1){
                return new AlipayRisk($channel);
            }else{
                return new Alipay($channel);
            }
        }elseif($channel['plugin'] == 'wxpayn' || $channel['plugin'] == 'wxpaynp'){
            return new Wxpay($channel);
        }elseif($channel['plugin'] == 'huifu' && $channel['type']==2){
            return new HuifuWxpay($channel);
        }elseif($channel['plugin'] == 'kuaiqian' && $channel['type']==2){
            return new KuaiqianWxpay($channel);
        }elseif($channel['plugin'] == 'kuaiqian' && $channel['type']==1){
            return new KuaiqianAlipay($channel);
        }elseif($channel['plugin'] == 'huolian' && $channel['type']==2){
            return new HuolianWxpay($channel);
        }elseif($channel['plugin'] == 'yeepay' && $channel['type']==1){
            return new YeepayAlipay($channel);
        }elseif($channel['plugin'] == 'yeepay' && $channel['type']==2){
            return new YeepayWxpay($channel);
        }elseif($channel['plugin'] == 'epayn' && $channel['type']==1){
            return new EpayAlipay($channel);
        }elseif($channel['plugin'] == 'epayn' && $channel['type']==2){
            return new EpayWxpay($channel);
        }elseif($channel['plugin'] == 'dinpay' && $channel['type']==1){
            return new DinpayAlipay($channel);
        }elseif($channel['plugin'] == 'dinpay' && $channel['type']==2){
            return new DinpayWxpay($channel);
        }elseif($channel['plugin'] == 'fuiou' && $channel['type']==2){
            return new FuiouWxpay($channel);
        }elseif($channel['plugin'] == 'fuiou2' && $channel['type']==2){
            return new Fuiou2Wxpay($channel);
        }elseif($channel['plugin'] == 'passpay' && $channel['type']==2){
            return new PasspayWxpay($channel);
        }elseif($channel['plugin'] == 'allinpay' && $channel['type']==1){
            return new AllinpayAlipay($channel);
        }elseif($channel['plugin'] == 'allinpay' && $channel['type']==2){
            return new AllinpayWxpay($channel);
        }elseif($channel['plugin'] == 'haipay' && $channel['type']==2){
            return new HaipayWxpay($channel);
        }elseif($channel['plugin'] == 'haipay' && $channel['type']==1){
            return new HaipayAlipay($channel);
        }elseif($channel['plugin'] == 'hnapay' && $channel['type']==2){
            return new HnapayWxpay($channel);
        }elseif($channel['plugin'] == 'heepay' && $channel['type']==1){
            return new HeepayAlipay($channel);
        }elseif($channel['plugin'] == 'heepay' && $channel['type']==2){
            return new HeepayWxpay($channel);
        }elseif($channel['plugin'] == 'hwkjpay' && $channel['type']==1){
            return new HwkjpayAlipay($channel);
        }elseif($channel['plugin'] == 'hwkjpay' && $channel['type']==2){
            return new HwkjpayWxpay($channel);
        }elseif($channel['plugin'] == 'fubei' && $channel['type']==1){
            return new FubeiAlipay($channel);
        }elseif($channel['plugin'] == 'yinyingtong' && $channel['type']==2){
            return new YinyingtongWxpay($channel);
        }elseif($channel['plugin'] == 'shengpay' && $channel['type']==1){
            return new ShengpayAlipay($channel);
        }elseif($channel['plugin'] == 'shengpay' && $channel['type']==2){
            return new ShengpayWxpay($channel);
        }elseif($channel['plugin'] == 'timezsoft' && $channel['type']==2){
            return new TimezsoftWxpay($channel);
        }elseif($channel['plugin'] == 'xhdpay' && $channel['type']==2){
            return new XhdpayWxpay($channel);
        }elseif($channel['plugin'] == 'kunpeng' && $channel['type']==1){
            return new KunpengAlipay($channel);
        }elseif($channel['plugin'] == 'kunpeng' && $channel['type']==2){
            return new KunpengWxpay($channel);
        }elseif($channel['plugin'] == 'lakalamoss' && $channel['type']==1){
            return new LakalamossAlipay($channel);
        }elseif($channel['plugin'] == 'lakalamoss' && $channel['type']==2){
            return new LakalamossWxpay($channel);
        }elseif($channel['plugin'] == 'helipay' && $channel['type']==1){
            return new HelipayWxpay($channel);
        }elseif($channel['plugin'] == 'helipay' && $channel['type']==2){
            return new HelipayWxpay($channel);
        }elseif($channel['plugin'] == 'sumpay' && $channel['type']==1){
            return new SumpayAlipay($channel);
        }elseif($channel['plugin'] == 'chinaso' && $channel['type']==1){
            return new ChinasoAlipay($channel);
        }
        return false;
    }

    public static function getChannel($row){
        global $DB;
        $channel = \lib\Channel::get($row['channel']);
        if(!$channel) return false;
        if($row['subchannel'] > 0){
            $channel = \lib\Channel::getSub($row['subchannel']);
        }
        elseif($channel['plugin'] == 'alipaysl' && substr($channel['appmchid'],0,1)=='['){
            $channelinfo = $DB->findColumn('user','channelinfo',['uid'=>$row['uid']]);
            if($channelinfo){
                $channel = \lib\Channel::get($row['channel'], $channelinfo);
            }
        }
        return $channel;
    }

    public static function autoHandle($trade_no, $status){
        global $DB, $conf;
        if(empty($trade_no)) return;
        if(is_array($trade_no)){
            foreach($trade_no as $item){
                self::autoHandle($item, $status);
            }
            return;
        }

        $order = $DB->find('order', 'uid,buyer,realmoney,status,ip,mobile', ['trade_no'=>$trade_no]);
        if(!$order) return;
        if($order['uid'] > 0){
            $gid = $DB->getColumn('user', 'gid', ['uid'=>$order['uid']]);
            $groupconfig = getGroupConfig($gid);
            $conf = array_merge($conf, $groupconfig);
        }

        //自动冻结订单
        if($conf['complain_freeze_order']==1){
            if($status < 2){ //冻结订单
                \lib\Order::freeze($trade_no);
            }elseif($status == 2){ //解冻订单
                \lib\Order::unfreeze($trade_no);
            }
        }

        //自动拉黑支付账号
        if($status < 2 && $conf['complain_auto_black'] == 1 && !empty($order['buyer'])){
            if(!$DB->getRow("select * from pre_blacklist where type=:type and content=:content limit 1", [':type'=>0, ':content'=>$order['buyer']])){
                $DB->insert('blacklist', ['type'=>0, 'content'=>$order['buyer'], 'addtime'=>'NOW()', 'remark'=>'投诉自动拉黑']);
            }
            if(!empty($order['mobile'])){
                if(!$DB->getRow("select * from pre_blacklist where type=:type and content=:content limit 1", [':type'=>0, ':content'=>$order['mobile']])){
                    $DB->insert('blacklist', ['type'=>0, 'content'=>$order['mobile'], 'addtime'=>'NOW()', 'remark'=>'投诉自动拉黑']);
                }
            }
        }
        if($status < 2 && $conf['complain_auto_ipblack'] > 0 && !empty($order['ip'])){
            if(!$DB->getRow("select * from pre_blacklist where type=:type and content=:content limit 1", [':type'=>1, ':content'=>$order['ip']])){
                switch($conf['complain_auto_ipblack']){
                    case 1: $endtime = date('Y-m-d H:i:s', strtotime('+3 days')); break;
                    case 2: $endtime = date('Y-m-d H:i:s', strtotime('+5 days')); break;
                    case 3: $endtime = date('Y-m-d H:i:s', strtotime('+7 days')); break;
                    default: $endtime = date('Y-m-d H:i:s', strtotime('+3 days')); break;
                }
                $DB->insert('blacklist', ['type'=>1, 'content'=>$order['ip'], 'addtime'=>'NOW()', 'endtime'=>$endtime, 'remark'=>'投诉自动拉黑']);
            }
        }

        //自动退款
        if($status == 0 && $conf['complain_auto_refund'] == 1 && (empty($conf['complain_auto_refund_money']) || $conf['complain_auto_refund_money']>=$order['realmoney']) && ($order['status'] == 1 || $order['status'] == 3)){
            $params = ['trade_no'=>$trade_no, 'money'=>$order['realmoney'], 'key'=>md5($trade_no.SYS_KEY.$trade_no)];
            get_curl($conf['localurl'].'api.php?act=refundapi', http_build_query($params));
            //\lib\Order::refund($trade_no, $order['realmoney'], 1);
        }
    }

    public static function sendmsg($msgtype, $thirdid){
        global $DB;
        $row = $DB->getRow("SELECT A.uid,A.trade_no,A.title,A.content,A.addtime,B.name ordername,B.money FROM pre_complain A LEFT JOIN pre_order B ON A.trade_no=B.trade_no WHERE thirdid=:thirdid", [':thirdid'=>$thirdid]);
        \lib\MsgNotice::send('complain', $row['uid'], ['trade_no'=>$row['trade_no'], 'title'=>$row['title'], 'content'=>$row['content'], 'type'=>$msgtype, 'name'=>$row['ordername'], 'money'=>$row['money'], 'time'=>$row['addtime']]);
    }
}