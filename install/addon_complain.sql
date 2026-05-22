CREATE TABLE IF NOT EXISTS `pre_complain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `paytype` int(11) NOT NULL,
  `channel` int(11) NOT NULL,
  `subchannel` int(11) NOT NULL DEFAULT '0',
  `source` tinyint(1) NOT NULL DEFAULT '0',
  `uid` int(11) NOT NULL,
  `trade_no` char(19) NOT NULL,
  `thirdid` varchar(100) NOT NULL,
  `type` varchar(30) NOT NULL,
  `title` varchar(300) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `edittime` datetime DEFAULT NULL,
  `thirdmchid` varchar(30) DEFAULT NULL,
  `info` text DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `uid` (`uid`),
 UNIQUE KEY `thirdid` (`thirdid`),
 KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `pre_complain`
ADD COLUMN `info` text DEFAULT NULL;

ALTER TABLE `pre_complain`
ADD COLUMN `trade_no_list` varchar(500) DEFAULT NULL,
ADD COLUMN `money` decimal(10,2) DEFAULT NULL;

INSERT INTO `pre_config` VALUES ('complain_open', '1');