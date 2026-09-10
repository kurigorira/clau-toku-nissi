<?php
/**
 * 設定ファイルのひな形。
 *
 * このファイルを config/config.php にコピーして、環境に合わせて書き換える。
 * config/config.php は .gitignore に入れてあり、リポジトリには含めない
 * （DBのパスワードを含むため）。
 */

return [

    // ---- データベース接続 ------------------------------------------------
    // 本番は MySQL / MariaDB。root は使わず、このアプリ専用のユーザを作り、
    // nissi データベースに対する SELECT/INSERT/UPDATE/DELETE のみを付与する。
    //   CREATE USER 'nissi'@'localhost' IDENTIFIED BY '...';
    //   GRANT SELECT,INSERT,UPDATE,DELETE ON nissi.* TO 'nissi'@'localhost';
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=nissi;charset=utf8mb4',
        'user' => 'nissi',
        'pass' => '',
    ],

    // ---- 入力者の特定方法 ------------------------------------------------
    // 'emr'   電子カルテから引き継がれた職員IDを使う（本番）
    // 'local' 職員ID＋パスワードでこのシステムにログインする（予備・保守用）
    //
    // ※ emr_param は電子カルテがIDを渡してくるパラメータ名。
    //    職員食注文アプリと同じ名前に合わせること。
    // ※ URLで平文のIDを受け取る方式は、他人のIDを名乗れてしまう。
    //    院内閉域網＋監査ログ（d_audit）で許容するか、emr_secret による
    //    検証を有効にするかは運用で決める。emr_secret が空文字なら検証しない。
    'auth' => [
        'mode'       => 'emr',
        'emr_param'  => 'staff_id',
        'emr_secret' => '',
    ],

    // ---- バックアップ ----------------------------------------------------
    // db/tools/backup.php が使う。復旧は db/tools/run_sql.php で流す。
    //
    // ※ バックアップには d_tokki の患者ID・氏名と、m_user のパスワードハッシュが入る。
    //   公開フォルダ（htdocs など）の中を指定すると backup.php は実行を拒否する。
    //   保存先のアクセス権も絞ること。
    'backup' => [
        'dir'     => 'C:/backup/nissi',   // まずここへ取る
        'copy_to' => '',                  // 院内の共有フォルダなど。例 //サーバ名/共有/backup/nissi
        'keep'    => 30,                  // 残す世代数
    ],

    // ---- その他 ----------------------------------------------------------
    'timezone' => 'Asia/Tokyo',

    // true にすると画面にエラー内容を出す。本番では必ず false。
    'debug' => false,
];
