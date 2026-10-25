# IPTV频道和EPG管理系统

## 项目概述

这是一个基于PHP和SQLite的IPTV频道和EPG（电子节目指南）管理系统，用于提供多种格式的直播源和节目指南数据。该系统支持多种客户端（如DIYP、TVBOX等），并提供了完整的数据管理和自动更新功能。

## 项目结构

```
├── channel.php          # SQLite数据库管理工具(phpLiteAdmin)
├── channel_epg.db       # SQLite数据库文件
├── epg.php              # EPG数据接口，支持多种格式
├── img.php              # Bing每日图片获取工具
├── index.html           # 系统入口页面
├── info.php             # PHP环境检测页面
├── l2list.php           # 直播源数据转换工具
├── live.php             # 直播频道接口，支持多种格式
├── myfile.php           # 在线文件管理器(SuExplorer)
├── xml2db.php           # EPG数据抓取和更新工具
└── 版本说明.txt         # 项目版本历史
```

## 主要功能

### 1. 直播频道接口 (live.php)

提供多种格式的直播源输出，支持以下格式：
- **TXT格式**：默认格式，适用于DIYP等应用
- **JSON格式**：通过参数 `t=json` 获取
- **M3U格式**：通过参数 `t=m3u` 获取
- **TVBOX格式**：通过参数 `t=tvbox` 获取，适用于TVBOX客户端

支持URL去重和合并功能，通过参数 `m=1`（仅去重）或 `m=2`（去重并合并）控制。

### 2. EPG数据接口 (epg.php)

提供多格式的节目指南数据输出：
- **SPTV JSON格式**：适用于SPTV客户端
- **DIYP JSON格式**：适用于DIYP客户端
- **IPTV XML格式**：通用XML格式

自动修正时间信息，确保节目时间准确性。

### 3. EPG数据自动更新 (xml2db.php)

- 从指定URL抓取XML格式的EPG数据
- 支持多个EPG数据源配置
- 自动将数据导入SQLite数据库
- 支持gzip压缩数据处理
- 支持https协议
- 自动处理节目时间，确保时间连续性

### 4. 数据库管理 (channel.php)

基于phpLiteAdmin的SQLite数据库管理工具，支持：
- 密码认证保护
- 数据库表结构管理
- 数据增删改查操作
- 兼容PHP 5.4、7.4和8.0版本

### 5. PHP环境检测 (info.php)

检测PHP环境和所需扩展是否安装：
- 显示服务器信息
- 检查Curl、SQLite3等扩展是否可用
- 提供phpinfo()访问
- 显示服务器互联网IP

### 6. 在线文件管理器 (myfile.php)

基于SuExplorer的在线文件管理工具，支持：
- 文件上传和下载
- 文件编辑（支持文本和脚本文件）
- 目录管理（创建、删除、重命名）
- 文件压缩和解压
- 用户认证保护（默认用户名：admin，密码：admin123）

### 7. 直播源数据转换工具 (l2list.php)

提供网页界面，用于转换直播源数据格式：
- 支持URL和本地文件输入
- 多种输出格式选择
- 结果下载功能

### 8. Bing每日图片 (img.php)

自动从Bing获取每日图片并保存到服务器：
- 自动创建图片缓存目录
- 支持随机返回图片
- 支持https协议

## 配置和使用

### 1. 系统要求

- PHP版本：5.4+（推荐7.4+）
- 必须安装的PHP扩展：
  - curl
  - gettext
  - openssl
  - pdo_sqlite
  - sockets
  - soap
  - sqlite3

### 2. 安装和初始化

1. 将所有文件部署到Web服务器的根目录或子目录
2. 确保Web服务器有写入权限（特别是imgs目录）
3. 访问 `xml2db.php` 初始化数据库（自动创建channel_epg.db）
4. 配置 `xml2db.php` 中的EPG数据源URL

### 3. 主要接口使用方法

#### 直播源接口
- TXT格式：`http://服务器地址/live.php`
- JSON格式：`http://服务器地址/live.php?t=json`
- M3U格式：`http://服务器地址/live.php?t=m3u`
- TVBOX格式：`http://服务器地址/live.php?t=tvbox`

#### EPG数据接口
- DIYP格式：`http://服务器地址/epg.php?ch=频道名称&date=日期`
- SPTV格式：`http://服务器地址/epg.php?channel=频道名称&date=日期`
- XML格式：`http://服务器地址/epg.php?ch=xml`

#### 数据更新
- 手动更新：直接访问 `xml2db.php`
- 自动更新：配置定时任务（如crontab）定期访问 `xml2db.php`

### 4. 数据库管理

- 访问 `channel.php` 进入数据库管理界面
- 默认用户名：admin，密码：admin123（可在channel.php中修改）

### 5. PHP环境检测

- 访问 `info.php` 检查PHP环境和扩展是否满足要求

## 配置文件说明

### channel.php
- 修改 `$PASSWORD` 变量更改数据库管理密码
- 修改 `$DATABASE` 变量指向数据库文件

### xml2db.php
- 配置 `$xmlurls` 数组添加或修改EPG数据源
- 设置 `$deletetoday` 控制是否删除今日数据

### live.php
- 配置输出格式和URL处理方式
- 设置默认输出格式

### epg.php
- 配置EPG数据输出格式
- 设置默认频道和日期

## 版本历史

###　　

＊＊＊＊＊＊＊＊＊＊＊＊＊

### 2026-10-24
1. grok修复xml2db.php数据处理逻辑,修改代码，数据库文件处理方式改变，执行时间减少。


### 2026-01-10
1. 修复xml2db.php数据处理逻辑
2. 修复epg.php数据查询和时间处理逻辑
3. 优化epg.php的XML格式输出，自动修正节目顺序

### 2026-01-06
1. 优化xml2db.php数据处理逻辑，每日时间处理改进

### 2024-02-26
1. 优化xml2db.php数据处理逻辑，添加数据源排序和节目去重

### 2024-01-14
1. 合并m3u.php、json.php、live.php、tvbox.php为单一文件live.php
2. 支持通过参数控制输出格式

### 2024-01-11
1. 优化channel.php的SQL语句处理逻辑
2. 优化epg.php的XML格式输出
3. 优化m3u.php和live.php的数据处理逻辑

### 2024-01-02
1. 修复channel.php，支持PHP 5.4、7.4和8.0

### 2023-12-22
1. 优化epg.php，添加调试日志开关

### 2023-12-21
1. 修复epg.php的内容处理逻辑

### 2023-12-19
1. 优化epg.php，添加UTF-8编码支持

### 2023-12-17
1. 优化m3u.php接口，支持list频道参数

### 2023-12-12
1. 更新myfile.php，支持PHP 7.4

### 2023-12-10
1. 优化epg.php性能，默认使用memcache缓存

### 2023-12-09
1. 优化xml2db，支持gzip和https
2. 优化epg.php，默认返回CCTV1节目单
3. 优化tvbox.php，支持TVBOX客户端接口

### 2023-12-08
1. 优化xml2db，支持多个xml源
2. 优化epg_channel表设计

### 2023-12-07
1. 优化epg.php，根据参数返回对应EPG数据

### 2023-12-04
1. 系统初始版本，支持PHP 5.x和7.x

## 注意事项

1. 请确保定期运行xml2db.php更新EPG数据，以保持数据最新
2. 首次使用时请检查PHP环境是否满足要求（通过info.php）
3. 建议修改默认密码以提高安全性
4. 定期备份channel_epg.db数据库文件
5. 如有问题，请参考版本说明.txt文件或检查日志

## 支持的客户端

- DIYP客户端
- TVBOX客户端
- SPTV客户端
- Kodi
- APTV
- TV+

## 数据源推荐

项目支持从以下EPG数据源获取数据：
- https://epg.51zmt.top:8000/e.xml
- https://epg.erw.cc/e.xml
- https://epg.112114.xyz/pp.xml
- https://epg.erw.cc/all.xml

## 许可证

本项目采用MIT许可证，可自由使用和修改。

---

感谢原作者 ：　oushaoming　（ https://github.com/oushaoming/channel_epg_php　）　　　

修改说明：　下载了原作者的脚本后，在自己的　ｏｐｅｎｗｒｔ　老年机中（MT7621硬件,　ImmortalWrt 24.10.4 ＋ PHP8）部署时发现了一些错误，通过　AI　多轮改进调试，现在已经部署成功。在此保存部署成功之后修改的版本。　


**作者**：aming.ou
**最后更新**：2026-01-10
