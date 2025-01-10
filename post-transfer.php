<?php
/*
Plugin Name:  POST TRANSFER
Description:  Provides the ability to export post data, including media in the media library, to a zip file and then import it.
Version:      0.1.0
Author:       Web Creator ITmaroon
Author URI:   https://itmaroon.net
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  post-transfer
Domain Path:  /languages
*/

if (! defined('ABSPATH')) exit;

//CSS等の読込
function itmar_post_tranfer_script_init()
{
  $css_path = plugin_dir_path(__FILE__) . 'css/transfer.css';
  wp_enqueue_style('transfer_handle', plugins_url('/css/transfer.css', __FILE__), array(), filemtime($css_path), 'all');
}
add_action('admin_enqueue_scripts', 'itmar_post_tranfer_script_init');



/**
 * 「ツール」にメニューを追加
 */
function itmar_post_tranfer_add_admin_menu()
{
  // 親メニュー（ツールメニューの下に追加）
  add_menu_page(
    'POST TRANSFER', // 設定画面のページタイトル.
    'POST TRANSFER', // 管理画面メニューに表示される名前.
    'manage_options',
    'itmar_post_tranfer_menu', // メニューのスラッグ.
    '', //コールバックは空
    'dashicons-admin-tools',  // アイコン
    75                        // メニューの位置
  );

  // 「インポート」サブメニュー
  add_submenu_page(
    'itmar_post_tranfer_menu',        // 親メニューのスラッグ
    __('Import', 'post-transfer'),      // ページタイトル
    __('import', 'post-transfer'),             // メニュータイトル
    'manage_options',         // 権限
    'itmar_post_tranfer_import',       // スラッグ
    'itmar_post_tranfer_import_page'   // コールバック関数
  );

  // 「エクスポート」サブメニュー
  add_submenu_page(
    'itmar_post_tranfer_menu',        // 親メニューのスラッグ
    __('Export', 'post-transfer'),      // ページタイトル
    __('export', 'post-transfer'),             // メニュータイトル
    'manage_options',         // 権限
    'itmar_post_tranfer_export',       // スラッグ
    'itmar_post_tranfer_export_page'   // コールバック関数
  );

  // サブメニューを削除
  remove_submenu_page('itmar_post_tranfer_menu', 'itmar_post_tranfer_menu');
}
add_action('admin_menu', 'itmar_post_tranfer_add_admin_menu');

/**
 * インポートの処理
 */
function itmar_post_tranfer_import_page()
{
  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(_e('You do not have sufficient permissions to access this page.', 'post-transfer'));
  }

?>
  <div class="wrap">
    <h1>データインポート</h1>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('custom_import_action', 'custom_import_nonce'); ?>

      <!-- ZIP ファイル選択 -->
      <table class="form-table">
        <tr>
          <th><label for="import_file">インポートする ZIP ファイル</label></th>
          <td>
            <input type="file" name="import_file" id="import_file" accept=".zip" required>
          </td>
        </tr>

        <!-- インポート方法選択 -->
        <tr>
          <th>インポート方法</th>
          <td>
            <label>
              <input type="radio" name="import_mode" value="update" checked> ID による上書き
            </label><br>
            <label>
              <input type="radio" name="import_mode" value="create"> 新規レコード追加
            </label>
          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" name="submit_import" class="button button-primary" value="インポート開始">
      </p>
    </form>
    <?php itmar_post_tranfer_import_json(); ?>
  </div>
<?php
}
//投稿タイプを取得する関数
function itmar_get_post_type_label($post_type)
{
  $post_type_object = get_post_type_object($post_type);
  return $post_type_object ? $post_type_object->label : '未登録の投稿タイプ';
}

/**
 * エクスポートの処理
 */

function itmar_post_tranfer_export_page()
{
  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(_e('You do not have sufficient permissions to access this page.', 'post-transfer'));
  }

?>
  <div class="wrap">
    <h1>カスタムエクスポート</h1>
    <p>エクスポートしたい記事を選択してください。</p>

    <form method="post" action="">
      <input type="hidden" name="export_action" value="export_json">
      <label>
        <input type="checkbox" name="include_custom_fields" value="1">
        カスタムフィールドも含める
      </label>
      <br><br>
      <?php
      // すべてのカスタム投稿タイプを取得（メディア "attachment" を除外）
      $all_post_types = get_post_types(['public' => true], 'objects');

      // 投稿タイプの順序を変更（投稿 → カスタム投稿 → 固定ページ）
      $ordered_post_types = [];
      if (isset($all_post_types['post'])) {
        $ordered_post_types['post'] = $all_post_types['post']; // 投稿を最初に
        unset($all_post_types['post']);
      }
      if (isset($all_post_types['page'])) {
        $page_type = $all_post_types['page']; // 固定ページを最後に
        unset($all_post_types['page']);
      }

      // カスタム投稿タイプを残りの投稿タイプとして格納
      foreach ($all_post_types as $key => $type) {
        if ($key !== 'attachment') { // メディア（"attachment"）を除外
          $ordered_post_types[$key] = $type;
        }
      }

      // 固定ページを最後に追加
      if (isset($page_type)) {
        $ordered_post_types['page'] = $page_type;
      }

      // 投稿タイプごとに記事一覧を表示
      foreach ($ordered_post_types as $post_type) {
        $current_page = isset($_GET["paged_{$post_type->name}"]) ? max(1, intval($_GET["paged_{$post_type->name}"])) : 1;
        $posts_per_page = 10;
        $offset = ($current_page - 1) * $posts_per_page;

        $query_args = [
          'post_type'      => $post_type->name,
          'posts_per_page' => $posts_per_page,
          'offset'         => $offset,
        ];
        $posts = get_posts($query_args);
        $total_posts = wp_count_posts($post_type->name)->publish;
        $total_pages = ceil($total_posts / $posts_per_page);

        if ($posts) {
          echo "<h2>{$post_type->label}</h2>";
          echo "<table class='widefat striped'>";
          echo "<thead><tr><th><input type='checkbox' id='select-all-{$post_type->name}'></th><th>タイトル</th><th>アイキャッチ</th></tr></thead>";
          echo "<tbody>";

          foreach ($posts as $post) {
            // アイキャッチ画像を取得
            $thumbnail = get_the_post_thumbnail($post->ID, 'thumbnail');

            echo "<tr>";
            echo "<td><input type='checkbox' name='export_posts[]' value='{$post->ID}'></td>";
            echo "<td>{$post->post_title}</td>";
            echo "<td>" . ($thumbnail ?: 'なし') . "</td>";
            echo "</tr>";
          }

          echo "</tbody></table>";

          // ページネーションの表示
          if ($total_pages > 1) {
            echo "<div class='tablenav'>";
            echo "<div class='tablenav-pages'>";

            // 前のページ
            if ($current_page > 1) {
              echo '<a class="button" href="?page=' . esc_attr($_GET['page']) . '&paged_' . $post_type->name . '=' . ($current_page - 1) . '">« 前へ</a>';
            }

            echo " ページ {$current_page} / {$total_pages} ";

            // 次のページ
            if ($current_page < $total_pages) {
              echo '<a class="button" href="?page=' . esc_attr($_GET['page']) . '&paged_' . $post_type->name . '=' . ($current_page + 1) . '">次へ »</a>';
            }

            echo "</div></div>";
          }
        }
      }
      ?>

      <p><input type="submit" name="export_selected" class="button button-primary" value="選択した記事をエクスポート"></p>
    </form>

    <script>
      document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll("input[id^='select-all-']").forEach(function(checkbox) {
          checkbox.addEventListener("change", function() {
            let table = this.closest("table");
            table.querySelectorAll("input[name='export_posts[]']").forEach(function(cb) {
              cb.checked = checkbox.checked;
            });
          });
        });
      });
    </script>
  </div>

<?php
}

//ZIP から画像をアップロードする関数
function itmar_import_thumbnail_from_zip($zip, $file_path, $post_id)
{
  $upload_dir = wp_upload_dir();
  $dest_path = $upload_dir['path'] . '/' . basename($file_path);

  // ZIP 内のファイルを展開
  if ($zip->locateName($file_path) !== false) {
    $zip->extractTo($upload_dir['path'], $file_path);
  } else {
    return false;
  }

  // WPメディアに登録
  $filetype = wp_check_filetype($dest_path);
  $attachment = array(
    'post_mime_type' => $filetype['type'],
    'post_title'     => sanitize_file_name(basename($dest_path)),
    'post_content'   => '',
    'post_status'    => 'inherit'
  );

  $attachment_id = wp_insert_attachment($attachment, $dest_path, $post_id);
  require_once(ABSPATH . 'wp-admin/includes/image.php');
  $attachment_data = wp_generate_attachment_metadata($attachment_id, $dest_path);
  wp_update_attachment_metadata($attachment_id, $attachment_data);

  return $attachment_id;
}


//インポートの実行処理
function itmar_process_import_data($decoded_data, $zip_path)
{
  echo '<h2>インポート結果</h2>';
  echo '<table class="widefat">';
  echo '<thead><tr><th>#</th><th>タイトル</th><th>投稿タイプ</th><th>結果</th></tr></thead>';
  echo '<tbody>';

  // ZIP ファイルを開く
  $zip = new ZipArchive;
  if ($zip->open($zip_path) !== true) {
    echo '<div class="error"><p>ZIPファイルの展開に失敗しました。</p></div>';
    return;
  }

  foreach ($decoded_data as $index => $entry) {
    $post_id = isset($entry['ID']) ? intval($entry['ID']) : 0;
    $post_title = isset($entry['title']) ? esc_html($entry['title']) : '（タイトルなし）';
    $post_type = isset($entry['post_type']) ? esc_html($entry['post_type']) : '（不明）';
    $post_date = isset($entry['date']) ? $entry['date'] : current_time('mysql');
    $post_modified = isset($entry['modified']) ? $entry['modified'] : current_time('mysql');
    $post_author = isset($entry['author']) ? get_user_by('login', $entry['author'])->ID ?? 1 : 1;
    $thumbnail_path = $entry['thumbnail_path'] ?? null;

    // 投稿タイプが登録されていない場合はスキップ
    if (!post_type_exists($post_type)) {
      echo "<tr><td>" . ($index + 1) . "</td><td>{$post_title}</td><td>{$post_type}</td><td>スキップ（未登録の投稿タイプ）</td></tr>";
      continue;
    }

    // 投稿データ
    $post_data = array(
      'ID'           => $post_id,
      'post_title'   => $post_title,
      'post_content' => $entry['content'] ?? '',
      'post_excerpt' => $entry['excerpt'] ?? '',
      'post_status'  => 'publish',
      'post_type'    => $post_type,
      'post_date'     => $post_date,
      'post_modified' => $post_modified,
      'post_author'   => $post_author,
    );

    // 既存投稿があれば上書き、なければ新規追加
    if ($post_id > 0 && get_post($post_id)) {
      $post_data['ID'] = $post_id;
      $updated_post_id = wp_update_post($post_data, true);
      $result = is_wp_error($updated_post_id) ? 'エラー（更新失敗）' : '上書き成功';
      $new_post_id = $updated_post_id;
    } else {
      $new_post_id = wp_insert_post($post_data, true);
      $result = is_wp_error($new_post_id) ? 'エラー（追加失敗）' : '新規追加';
    }

    // アイキャッチ画像の設定
    if (!empty($thumbnail_path) && $new_post_id && !is_wp_error($new_post_id)) {
      $attachment_id = itmar_import_thumbnail_from_zip($zip, $thumbnail_path, $new_post_id);
      if ($attachment_id) {
        set_post_thumbnail($new_post_id, $attachment_id);
      }
    }

    echo "<tr><td>" . ($index + 1) . "</td><td>{$post_title}</td><td>{$post_type}</td><td>{$result}</td></tr>";
  }

  echo '</tbody>';
  echo '</table>';

  $zip->close();
}

// JSONインポート処理
function itmar_post_tranfer_import_json()
{
  if (!isset($_POST['submit_import']) || !check_admin_referer('custom_import_action', 'custom_import_nonce')) {
    return;
  }

  // ファイルが選択されていない場合はエラー
  if (empty($_FILES['import_file']['name'])) {
    echo '<div class="error"><p>ZIPファイルを選択してください。</p></div>';
    return;
  }

  // アップロードされたZIPファイルの処理
  $file = $_FILES['import_file'];
  $upload_dir = wp_upload_dir();
  $zip_path = $upload_dir['path'] . '/' . basename($file['name']);

  // ZIPファイルを一時ディレクトリに保存
  if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
    echo '<div class="error"><p>ファイルのアップロードに失敗しました。</p></div>';
    return;
  }

  // ZIPを展開
  $zip = new ZipArchive;
  if ($zip->open($zip_path) === true) {
    $json_filename = 'export_data.json';
    $json_path = $upload_dir['path'] . '/' . $json_filename;

    // JSONファイルを展開
    if ($zip->locateName($json_filename) !== false) {
      $zip->extractTo($upload_dir['path'], $json_filename);
    } else {
      echo '<div class="error"><p>ZIPファイルに "export_data.json" が含まれていません。</p></div>';
      $zip->close();
      unlink($zip_path); // ZIP削除
      return;
    }

    $zip->close();

    // JSONファイルの読み込み
    if (file_exists($json_path)) {
      $json_data = file_get_contents($json_path);
      $decoded_data = json_decode($json_data, true);

      if (!empty($decoded_data) && is_array($decoded_data)) {
        itmar_process_import_data($decoded_data, $zip_path);
      } else {
        echo '<div class="error"><p>JSONデータの解析に失敗しました。</p></div>';
      }

      unlink($json_path); // JSON削除
    } else {
      echo '<div class="error"><p>展開したJSONファイルが見つかりません。</p></div>';
    }
  } else {
    echo '<div class="error"><p>ZIPファイルの展開に失敗しました。</p></div>';
  }

  unlink($zip_path); // ZIP削除
}

//ダウンロード関数
function itmar_download_image($image_url, $save_folder)
{
  // 画像のURLからファイル名を取得
  $parse_url = parse_url($image_url, PHP_URL_PATH);
  if (!$parse_url) { //ファイル名がパースできない場合
    return false;
  }
  $image_filename = basename(parse_url($image_url, PHP_URL_PATH));

  // 保存先のパスを決定
  $image_path = $save_folder . $image_filename;

  // 既にファイルが存在する場合はダウンロードしない
  if (file_exists($image_path)) {
    return $image_path;
  }

  //ローカルサーバーか否かの判定
  $is_local_environment = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local';

  $response = wp_remote_get($image_url, [
    'sslverify' => !$is_local_environment, // ローカルサーバーではSSL 検証を無効化
    'timeout'   => 20, // タイムアウト設定
  ]);

  if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    return false; // 取得失敗
  }

  $image_data = wp_remote_retrieve_body($response);
  if (!$image_data) {
    return false;
  }

  // ファイルを保存
  if (file_put_contents($image_path, $image_data) !== false) {
    return $image_path; // 成功したらファイル名を返す
  }

  return false; // 失敗した場合
}

//コンテンツからメディアURLを抜き出す関数
function itmar_extract_media_urls($content)
{
  $media_urls = [];

  // 画像・メディアURLを正規表現で抽出
  preg_match_all('/https?:\/\/[^\"\'\s]+(?:jpg|jpeg|png|gif|mp4|mp3|pdf)/i', $content, $matches);

  if (!empty($matches[0])) {
    $media_urls = array_unique($matches[0]); // 重複を除外
  }

  return $media_urls;
}

function itmar_is_acf_active()
{
  return function_exists('get_field') && function_exists('get_field_object');
}


// JSONエクスポート処理
function itmar_post_tranfer_export_json()
{
  if (isset($_POST['export_action']) && $_POST['export_action'] === 'export_json' && isset($_POST['export_posts'])) {
    $post_ids = array_map('intval', $_POST['export_posts']);
    $include_custom_fields = isset($_POST['include_custom_fields']);

    $export_data = [];
    $upload_dir = wp_upload_dir();
    $save_folder = $upload_dir['basedir'] . '/exported_media/'; // 画像保存用ディレクトリ

    // ディレクトリがない場合は作成
    if (!file_exists($save_folder)) {
      wp_mkdir_p($save_folder);
    }

    // ZIP ファイルの保存先
    $zip_filename = $upload_dir['basedir'] . '/exported_data.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      wp_die('ZIP ファイルを作成できませんでした');
    }

    foreach ($post_ids as $post_id) {
      $post = get_post($post_id);
      if ($post) {
        $post_data = [
          'ID'            => $post->ID,
          'title'         => $post->post_title,
          'content'       => apply_filters('the_content', $post->post_content),
          'excerpt'       => $post->post_excerpt,
          'date'          => $post->post_date,
          'modified'      => $post->post_modified,
          'author'        => get_the_author_meta('display_name', $post->post_author),
          'post_type'     => $post->post_type,
          'thumbnail_url' => get_the_post_thumbnail_url($post->ID, 'full'), // 画像URL
          'thumbnail_path' => null, // 保存後の画像パス
        ];

        // カスタムフィールドを含める場合
        if ($include_custom_fields) {
          //wp_postmetaから取り出す全ての関連データ
          $custom_fields = get_post_meta($post->ID);

          //WordPress の register_post_meta() で登録されたものだけを取得
          $registered_meta_keys = get_registered_meta_keys('post', $post->post_type);

          //カスタムフィールドの処理
          foreach ($custom_fields as $key => $value) {
            //acfがインストールされているときの処理
            if (itmar_is_acf_active()) {
              if (strpos($key, '_') !== 0) { // `_` 付きのフィールドをスキップ
                $field_object = get_field_object($key, $post->ID);
                //ACFフィールドである
                if ($field_object && isset($field_object['type'])) {
                  //フィールドタイプがイメージやファイルのものならダウンロード処理
                  if ($field_object['type'] === 'image' || $field_object['type'] === 'file') {
                    $value = get_field($key, $post->ID);
                    if ($value) { //値がなければ処理しない
                      //値が数値ならurlを取得、配列なら`url` を取得、それ以外はそのまま
                      if (is_numeric($value)) {
                        $media_url = wp_get_attachment_url($value);
                      } elseif (is_array($value) && isset($value['url'])) {
                        $media_url = $value['url'];
                      } else {
                        $media_url = $value;
                      }
                      //ダウンロード処理
                      if ($media_url) {
                        $media_path = itmar_download_image($media_url, $save_folder);
                        if ($media_path) {
                          $relative_path = 'exported_media/' . basename($media_path);
                          $zip->addFile($media_path, $relative_path);
                          $post_data['custom_fields'][$key] = $relative_path;
                        }
                      }
                    }
                  } else if ($field_object['type'] === 'group') {
                    //フィールド種別がグループの時は値を_groupとする
                    $post_data['custom_fields'][$key] = '_group';
                  } else {
                    $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
                  }
                  //WordPress の register_post_meta() で登録されたもの
                } else if (array_key_exists($key, $registered_meta_keys)) {
                  $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
                }
              }
              //acfがインストールされていないときの処理
            } else {
              $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
            }
          }
        }

        // アイキャッチ画像のダウンロード処理
        if ($post_data['thumbnail_url']) {
          if ($post_data['thumbnail_url']) {
            // ダウンロードの結果からパス・ファイル名を取得
            $image_path = itmar_download_image($post_data['thumbnail_url'], $save_folder);
            if ($image_path) {
              //ダウンロードが成功したらpost_dataのthumbnail_pathに記録して、zipファイルに追加
              $image_filename = basename($image_path);
              $post_data['thumbnail_path'] = 'exported_media/' . $image_filename; // ZIP 内のパス
              $zip->addFile($image_path, 'exported_media/' . $image_filename);
            }
          }
        }

        // 投稿本文内のメディアURLをダウンロード
        $content_media_urls = itmar_extract_media_urls($post->post_content);
        foreach ($content_media_urls as $media_url) {
          $media_path = itmar_download_image($media_url, $save_folder);
          if ($media_path) {
            //ダウンロードが成功したらコンテンツ内のファイルパスを書き換えて、zipファイルに追加
            $relative_path = 'exported_media/' . basename($media_path);
            $modified_content = str_replace($media_url, $relative_path, $post->post_content);
            $post_data['content'] = $modified_content;
            $zip->addFile($media_path, $relative_path);
          }
        }

        $export_data[] = $post_data;
      }
    }

    // JSON を ZIP に追加
    $json_data = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json_path = $upload_dir['basedir'] . '/export_data.json';
    file_put_contents($json_path, $json_data);
    $zip->addFile($json_path, 'export_data.json');

    // ZIP を閉じる
    $zip->close();

    // ダウンロード用ヘッダー
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="exported_data.zip"');
    header('Content-Length: ' . filesize($zip_filename));

    // ZIP ファイルを出力
    readfile($zip_filename);

    // 一時ファイルを削除
    unlink($json_path);
    unlink($zip_filename);
    exit;
  }
}

add_action('admin_init', 'itmar_post_tranfer_export_json');
