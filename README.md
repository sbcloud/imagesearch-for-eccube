# Aliyun Image Search
https://www.alibabacloud.com/ja/product/imagesearch

## インストール方法
- ソースコードをapp/Pluginに展開します。
- EC-Cubeのディレクトリに以下のコマンドを実行し、プラグインをインストールします。
　bin/console eccube:plugin:install --code=ImageSearch
- EC-Cubeのディレクトリに以下のコマンドを実行し、プラグインを有効化します。
　bin/console eccube:plugin:enable --code=ImageSearch
- EC-Cubeのディレクトリに以下のコマンドを実行し、AlibabaCloudのSDKをインストールします。
　composer require alibabacloud/sdk 1.8.47
- EC-Cubeの管理画面からパラメータを設定します。
　/%eccube_admin_route%/image_search/config
