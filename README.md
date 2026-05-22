<div align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&height=220&color=0:0B1F5E,50:1D4ED8,100:38BDF8&text=%E5%BD%A9%E8%99%B9%E6%98%93%E6%94%AF%E4%BB%98%E7%B3%BB%E7%BB%9F&fontColor=ffffff&fontSize=42&fontAlign=50&fontAlignY=40&desc=Epay%20%7C%20Open%20Source&descAlign=50&descAlignY=62" alt="banner" />
</div>

<div align="center">
  <img src="https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 7.4+" />
  <img src="https://img.shields.io/badge/MySQL-5.6+-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL 5.6+" />
  <a href="https://github.com/Yufeifeio/Epay-epusdt">
    <img src="https://img.shields.io/badge/Epusdt-%E6%8F%92%E4%BB%B6-26A17B?style=for-the-badge&logo=tether&logoColor=white" alt="Epusdt 插件" />
  </a>
  <a href="https://t.me/pyufc">
    <img src="https://img.shields.io/badge/%E9%B1%BC%E8%82%A5%E8%82%A5-%40pyufc-229ED9?style=for-the-badge&logo=telegram&logoColor=white" alt="鱼肥肥 @pyufc" />
  </a>
</div>

# 彩虹易支付系统

彩虹易支付系统由郑州追梦网络科技有限公司开发，是一款开源的免签约支付产品，能够帮助开发者一站式接入支付宝、微信、财付通、QQ 钱包等多种支付方式，实现高效的支付集成。

## 联系方式

✈ 鱼肥肥 [@pyufc](https://t.me/pyufc)

## 功能特色

- 多渠道支付集成：支持支付宝、微信、财付通、QQ 钱包、微信 WAP、银联等多种支付方式
- 便捷的支付解决方案：简化支付流程，支持快速集成和上线，提供完整的 API 接口
- 后台管理和数据统计：提供支付统计、代付统计、利润分析等多种后台管理功能
- 安全可靠：采用 RSA 公私钥验证，支持风控检测和黑名单管理
- 插件扩展：支持丰富的支付插件，可根据需求灵活扩展
- 移动端优化：全新的手机版支付页面，支持各种移动端支付场景

## 环境要求

- PHP 7.4 及以上
- MySQL 5.6 及以上
- 已开启 PDO、OpenSSL、cURL、JSON

## 安装说明

1. 上传项目到网站目录。
2. 配置站点伪静态，参考根目录的 `.htaccess`、`nginx.txt` 或 `IIS.txt`。
3. 访问 `/install/` 按提示完成安装。
4. 安装完成后确认生成 `install/install.lock`。

## 目录说明

- `admin/` 管理后台
- `user/` 商户中心
- `plugins/` 支付插件
- `paypage/` 收款页与支付页
- `includes/` 核心类库与公共函数

## 插件

- `epusdt` 插件：[Epay-epusdt](https://github.com/Yufeifeio/Epay-epusdt)
