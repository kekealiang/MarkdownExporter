<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 导出文章为 Hexo 格式 Markdown (支持 Front Matter)
 * 
 * @package MarkdownExporter
 * @author WAN
 * @version 1.0.4
 * @link https://typecho.org
 */
class MarkdownExporter_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        Helper::addAction('markdown-exporter', 'MarkdownExporter_Action');
        Typecho_Plugin::factory('admin/footer.php')->end = array('MarkdownExporter_Plugin', 'renderScript');
        Typecho_Plugin::factory('admin/write-post.php')->option = array('MarkdownExporter_Plugin', 'renderSingleButton');
        return _t('插件激活成功');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removeAction('markdown-exporter');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form) {}

    /**
     * 个人配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 注入批量导出 JS 脚本与独立按钮
     */
    public static function renderScript()
    {
        $request = Typecho_Request::getInstance();
        if (strpos($request->getRequestUrl(), 'manage-posts.php') === false) {
            return;
        }

        $actionUrl = Typecho_Common::url('/action/markdown-exporter?do=export', Helper::options()->index);
        ?>
        <script>
        (function () {
            function injectExportBtn() {
                if (document.getElementById('hexo-export-btn')) return;

                var btn = document.createElement('button');
                btn.id = 'hexo-export-btn';
                btn.type = 'button';
                btn.innerText = '批量导出选中文章为 MD';
                btn.style.cssText = 'margin-left: 15px; padding: 4px 12px; background-color: #f0ad4e; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; vertical-align: middle;';

                var titleNode = document.querySelector('.typecho-page-title h2') || document.querySelector('h2');
                if (titleNode) {
                    titleNode.appendChild(btn);
                } else {
                    btn.style.position = 'fixed';
                    btn.style.top = '60px';
                    btn.style.right = '20px';
                    btn.style.zIndex = '9999';
                    document.body.appendChild(btn);
                }

                btn.onclick = function (e) {
                    e.preventDefault();
                    var checkboxes = document.querySelectorAll('input[name="cid[]"]:checked');
                    var ids = [];
                    for (var i = 0; i < checkboxes.length; i++) {
                        ids.push(checkboxes[i].value);
                    }

                    if (ids.length === 0) {
                        alert('请先勾选需要导出的文章！');
                        return;
                    }

                    window.location.href = '<?php echo $actionUrl; ?>&cids=' + ids.join(',');
                };
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', injectExportBtn);
            } else {
                injectExportBtn();
            }
        })();
        </script>
        <?php
    }

    /**
     * 文章编辑页侧边栏注入按钮
     */
    public static function renderSingleButton($post)
    {
        if (!empty($post->cid)) {
            $actionUrl = Typecho_Common::url('/action/markdown-exporter?do=export&cids=' . $post->cid, Helper::options()->index);
            echo '<section class="typecho-post-option"><label class="typecho-label">Hexo 导出</label>';
            echo '<a href="' . $actionUrl . '" class="btn btn-s">' . _t('导出为 .md 文件') . '</a ></section>';
        }
    }
}
