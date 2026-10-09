<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class MarkdownExporter_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            die('Access Denied');
        }

        $do = $this->request->get('do');
        if ($do === 'export') {
            $this->exportPosts();
        }
    }

    private function exportPosts()
    {
        @ini_set('display_errors', 0);
        @error_reporting(0);
        @set_time_limit(0);
        @ini_set('memory_limit', '256M');

        $cidsParam = $this->request->get('cids');
        if (empty($cidsParam)) {
            die('请选择要导出的文章');
        }

        $cids = array_map('intval', explode(',', $cidsParam));
        $db = Typecho_Db::get();

        // 导出单篇 MD
        if (count($cids) === 1) {
            $post = $this->getPostData($db, $cids[0]);
            if ($post) {
                $dummyZip = null;
                $processedImages = array();
                $post = $this->parseTypechoAttachmentsAndImages($post, $db, $dummyZip, $processedImages);
                
                $content = $this->buildHexoMarkdown($post);
                $filename = $this->sanitizeFilename($post['title']) . '.md';

                while (ob_get_level()) {
                    ob_end_clean();
                }

                header('Content-Type: text/markdown; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
                echo $content;
                exit;
            }
        } 
        // 导出多篇 ZIP 打包
        else if (count($cids) > 1 && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $zipFileName = 'hexo_export_' . date('Ymd_His') . '.zip';
            $zipPath = sys_get_temp_dir() . '/' . $zipFileName;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $processedImages = array();

                foreach ($cids as $cid) {
                    $post = $this->getPostData($db, $cid);
                    if ($post) {
                        $processedPost = $this->parseTypechoAttachmentsAndImages($post, $db, $zip, $processedImages);
                        $content = $this->buildHexoMarkdown($processedPost);
                        $filename = $this->sanitizeFilename($post['title']) . '.md';
                        
                        $zip->addFromString('posts/' . $filename, $content);
                    }
                }
                $zip->close();

                if (file_exists($zipPath) && filesize($zipPath) > 0) {
                    while (ob_get_level()) {
                        ob_end_clean();
                    }

                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                    header('Content-Length: ' . filesize($zipPath));
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    readfile($zipPath);
                    @unlink($zipPath);
                    exit;
                } else {
                    die('生成的 ZIP 文件为空或创建失败');
                }
            }
        } else {
            die('当前服务器环境未安装 ZipArchive 扩展');
        }
    }

    private function getPostData($db, $cid)
    {
        $post = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'post'));

        if (!$post) return null;

        $categories = $db->fetchAll($db->select('table.metas.name')->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'category'));

        $tags = $db->fetchAll($db->select('table.metas.name')->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'tag'));

        $post['categories'] = array_column($categories, 'name');
        $post['tags'] = array_column($tags, 'name');

        $fields = $db->fetchAll($db->select()->from('table.fields')
            ->where('cid = ?', $cid));

        $customFields = array();
        foreach ($fields as $field) {
            $val = !empty($field['str_value']) ? $field['str_value'] : $field['bytes_value'];
            $customFields[$field['name']] = $val;
        }
        $post['fields'] = $customFields;

        return $post;
    }

    private function parseTypechoAttachmentsAndImages($post, $db, &$zip, array &$processedImages)
    {
        $text = $post['text'];

        // 核心改动：移除开头的 <!--markdown--> 标志
        $text = preg_replace('/^\s*<!--markdown-->/i', '', $text);

        if (!empty($post['fields']) && is_array($post['fields'])) {
            foreach ($post['fields'] as $fieldName => $fieldValue) {
                if (empty($fieldValue)) continue;

                $isImageField = in_array(strtolower($fieldName), array('thumb', 'cover', 'banner', 'image', 'thumbnail', 'top_img')) ||
                                preg_match('/\.(jpg|jpeg|png|gif|webp|svg|bmp)/i', $fieldValue);

                if ($isImageField) {
                    $filename = $this->addImgToZipAndGetFilename($fieldValue, $zip, $processedImages);
                    $post['fields'][$fieldName] = '../images/' . $filename;
                }
            }
        }

        $attachments = $db->fetchAll($db->select()->from('table.contents')
            ->where('parent = ?', $post['cid'])
            ->where('type = ?', 'attachment'));

        $attachMap = array();
        $attachList = array();
        foreach ($attachments as $attach) {
            $textData = @unserialize($attach['text']);
            if (is_array($textData) && isset($textData['path'])) {
                $attachInfo = array(
                    'cid'   => $attach['cid'],
                    'title' => $attach['title'],
                    'path'  => $textData['path'],
                    'url'   => Helper::options()->siteUrl . ltrim($textData['path'], '/')
                );
                $attachMap[$attach['cid']] = $attachInfo;
                $attachList[] = $attachInfo;
            }
        }

        $refLinks = array();
        if (preg_match_all('/^\s*\[([a-zA-Z0-9_\-]+)\]:\s*(https?:\/\/[^\s]+|\/?[^\s]+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $refLinks[$match[1]] = $match[2];
            }
            $text = preg_replace('/^\s*\[([a-zA-Z0-9_\-]+)\]:\s*(https?:\/\/[^\s]+|\/?[^\s]+)\s*$/m', '', $text);
        }

        $attachIdx = 0;
        $text = preg_replace_callback('/!\[(.*?)\]\[(.*?)\]/', function($m) use ($refLinks, $attachMap, $attachList, &$attachIdx, &$zip, &$processedImages) {
            $alt = $m[1];
            $key = trim($m[2]);
            $imgUrlOrPath = '';

            if ($key !== '' && isset($refLinks[$key])) {
                $imgUrlOrPath = $refLinks[$key];
            } else if (is_numeric($key) && isset($attachMap[$key])) {
                $imgUrlOrPath = $attachMap[$key]['path'];
            } else if (isset($attachList[$attachIdx])) {
                $imgUrlOrPath = $attachList[$attachIdx]['path'];
                $attachIdx++;
            }

            if (!empty($imgUrlOrPath)) {
                $filename = $this->addImgToZipAndGetFilename($imgUrlOrPath, $zip, $processedImages);
                return "![" . $alt . "](../images/" . $filename . ")";
            }

            return $m[0];
        }, $text);

        $text = preg_replace_callback('/!\[(.*?)\]\(([^\s\)\"\']+)\)/', function($m) use (&$zip, &$processedImages) {
            $filename = $this->addImgToZipAndGetFilename($m[2], $zip, $processedImages);
            return "![" . $m[1] . "](../images/" . $filename . ")";
        }, $text);

        $text = preg_replace_callback('/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function($m) use (&$zip, &$processedImages) {
            $filename = $this->addImgToZipAndGetFilename($m[1], $zip, $processedImages);
            return '< img src="../images/' . $filename . '">';
        }, $text);

        $post['text'] = trim($text);
        return $post;
    }

    private function addImgToZipAndGetFilename($urlOrPath, &$zip, array &$processedImages)
    {
        $pathInfo = parse_url($urlOrPath);
        $urlPath = isset($pathInfo['path']) ? $pathInfo['path'] : $urlOrPath;
        $filename = basename($urlPath);

        if (empty($filename) || $filename === '.' || $filename === '..') {
            $filename = 'img_' . uniqid() . '.png';
        }

        if (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename .= '.png';
        }

        if ($zip instanceof ZipArchive) {
            $possibleLocalPaths = array(
                __TYPECHO_ROOT_DIR__ . '/' . ltrim($urlPath, '/'),
                __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . $filename,
                __TYPECHO_ROOT_DIR__ . '/img/' . $filename,
                __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . ltrim($urlPath, '/')
            );

            $foundFilePath = null;
            foreach ($possibleLocalPaths as $pPath) {
                if (file_exists($pPath) && is_file($pPath)) {
                    $foundFilePath = $pPath;
                    break;
                }
            }

            $zipImagePath = 'images/' . $filename;

            if ($foundFilePath && !isset($processedImages[$filename])) {
                $zip->addFile($foundFilePath, $zipImagePath);
                $processedImages[$filename] = true;
            } else if ((strpos($urlOrPath, 'http://') === 0 || strpos($urlOrPath, 'https://') === 0) && !isset($processedImages[$filename])) {
                $imgContent = @file_get_contents($urlOrPath);
                if ($imgContent !== false) {
                    $zip->addFromString($zipImagePath, $imgContent);
                    $processedImages[$filename] = true;
                }
            }
        }

        return $filename;
    }

    private function buildHexoMarkdown($post)
    {
        $date = date('Y/m/d H:i:s', $post['created']);
        $title = str_replace('"', '\"', $post['title']);

        $md = "-----\n";
        $md .= "title: \"{$title}\"\n";
        $md .= "date: {$date}\n";

        if (!empty($post['slug'])) {
            $md .= "permalink: {$post['slug']}\n";
        }

        if (!empty($post['categories'])) {
            $md .= "categories:\n";
            foreach ($post['categories'] as $cat) {
                $md .= "  - " . $cat . "\n";
            }
        }

        if (!empty($post['tags'])) {
            $md .= "tags:\n";
            foreach ($post['tags'] as $tag) {
                $md .= "  - " . $tag . "\n";
            }
        }

        if (!empty($post['fields']) && is_array($post['fields'])) {
            foreach ($post['fields'] as $key => $val) {
                if ($val === '' || $val === null) continue;
                
                if (in_array(strtolower($key), array('thumb', 'cover', 'banner', 'thumbnail', 'top_img'))) {
                    $md .= "cover: \"{$val}\"\n";
                    $md .= "{$key}: \"{$val}\"\n";
                } else {
                    $md .= "{$key}: \"{$val}\"\n";
                }
            }
        }

        $md .= "-----\n\n";

        // 去除 Typecho 特有的 <!--markdown--> 前缀（双重保险）
        $text = preg_replace('/^\s*<!--markdown-->/i', '', $post['text']);
        $text = str_replace('<!--more-->', '<!-- more -->', $text);
        
        $md .= trim($text);

        return $md;
    }

    private function sanitizeFilename($filename)
    {
        return preg_replace('/[\/\\:\*\?"<>\|]/', '_', $filename);
    }
}
