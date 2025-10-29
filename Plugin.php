<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 隐藏内容，支持评论可见和密码可见两种方式
 * 
 * @package HideContent
 * @author 笨蛋五十
 * @version 1.0.0
 * @link https://github.com/wanxi50
 */
class HideContent_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 注册编辑器底部钩子
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('HideContent_Plugin', 'renderEditor');
        
        // 注册内容解析钩子
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('HideContent_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('HideContent_Plugin', 'parse');
        
        // 注册AJAX Action
        Helper::addAction('hide-content', 'HideContent_Action');
        
        return _t('插件已激活，在文章编辑器中点击按钮，选择隐藏方式（评论后可见 / 密码可见）');
    }
    
    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        Helper::removeAction('hide-content');
        return _t('插件已被禁用');
    }
    
    /**
     * 获取插件配置面板
     */
public static function config(Typecho_Widget_Helper_Form $form)
{
        require_once 'Config.php';
        HideContent_Config::config($form);
}
    
    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 输出编辑器资源
     */
    public static function renderEditor()
    {
        $pluginUrl = Helper::options()->pluginUrl . '/HideContent';
        $assetDir = dirname(__FILE__) . '/assets/';
        ?>
        <script src="<?php echo $pluginUrl; ?>/assets/<?php echo (is_readable($assetDir . 'editor.min.js') ? 'editor.min.js' : 'editor.js'); ?>"></script>
        <?php
    }
    
    /**
     * 解析内容，处理隐藏内容标签
     */
    public static function parse($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;

        if ($widget instanceof Widget_Archive) {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('HideContent');
            
            // 检查是否包含隐藏内容
            $hasEncryptedContent = preg_match('/<details[^>]+hide-content/i', $text);
            
            // 只在文章详情页且包含隐藏内容时插入样式，避免在首页摘要中显示CSS代码
            if ($hasEncryptedContent && $widget->is('single')) {
                // 样式选择：空则用默认CSS文件；有内容则完全使用自定义样式
                $pluginUrl = Helper::options()->pluginUrl . '/HideContent';
                $assetDir = dirname(__FILE__) . '/assets/';
                $customStyle = isset($options->customStyle) ? trim($options->customStyle) : '';
                if (empty($customStyle)) {
                    $cssFile = is_readable($assetDir . 'hide-content.min.css') ? 'hide-content.min.css' : 'hide-content.css';
                    $text = '<link rel="stylesheet" href="' . $pluginUrl . '/assets/' . $cssFile . '" />' . $text;
                } else {
                    $text = '<style>' . $customStyle . '</style>' . $text;
                }

                // 插入前端脚本配置
                $actionUrl = Typecho_Common::url('action/hide-content', Helper::options()->index);
                $commentNotice = isset($options->commentNotice) ? $options->commentNotice : '请发表评论后查看';
                $passwordNotice = isset($options->passwordNotice) ? $options->passwordNotice : '请输入密码后查看';
                
                // 错误提示（HTML）配置
                $commentErrorHtml = isset($options->commentErrorHtml) ? $options->commentErrorHtml : '<span>请先发表评论后查看</span>';
                $passwordErrorHtml = isset($options->passwordErrorHtml) ? $options->passwordErrorHtml : '<span>密码错误，请重试</span>';
                
                $text .= '<script>window.TypechoHideContent = {' .
                    'actionUrl: "' . $actionUrl . '", ' .
                    'cid: ' . $widget->cid . ', ' .
                    'commentNotice: ' . json_encode($commentNotice) . ', ' .
                    'passwordNotice: ' . json_encode($passwordNotice) . ', ' .
                    'commentErrorHtml: ' . json_encode($commentErrorHtml) . ', ' .
                    'passwordErrorHtml: ' . json_encode($passwordErrorHtml) .
                '};</script>';
                
                // 插入前端脚本（存在即用 .min 回退）
                $decryptFile = is_readable($assetDir . 'decrypt.min.js') ? 'decrypt.min.js' : 'decrypt.js';
                $text .= '<script src="' . $pluginUrl . '/assets/' . $decryptFile . '"></script>';
            }
            
            // 处理隐藏内容块
            if ($hasEncryptedContent) {
                $text = self::processEncryptedBlocks($text, $widget, $options);
            }
        }

        return $text;
    }
    
    /**
     * 处理隐藏内容块
     * 
     * @param string $text 文本内容
     * @param Widget_Archive $widget 文章对象
     * @param Typecho_Widget $options 插件配置
     * @return string
     */
    private static function processEncryptedBlocks($text, $widget, $options)
    {
        // 功能开关：关闭时直接展示隐藏内容
        $hideEnabled = isset($options->hideEnabled) ? (string)$options->hideEnabled : '1';
        if ($hideEnabled === '0') {
            return self::showDecryptedForPrivileged($text);
        }

        // 检查用户权限
        $user = Typecho_Widget::widget('Widget_User');
        $isAdmin = $user->hasLogin() && $user->group == 'administrator';
        $isAuthor = $user->hasLogin() && $user->uid == $widget->authorId;
        
        // 配置：管理员/作者直通
        $privilegedBypass = isset($options->privilegedBypass) ? (string)$options->privilegedBypass : '1';
        if ($privilegedBypass === '1') {
        if ($isAdmin || $isAuthor) {
            return self::showDecryptedForPrivileged($text);
            }
        }

        // 使用会话内稳定映射为每个隐藏块生成稳定的 block_id
        
        // 匹配所有 <details hide-content> 标签
        $text = preg_replace_callback(
            '/<details([^>]*hide-content="(comment|password)"[^>]*)>(.*?)<\/details>/is',
            function($matches) use ($widget, $options) {
                $attributes = $matches[1];
                $encryptType = $matches[2];
                $content = $matches[3];

                $blockId = self::getStableBlockId($widget->cid, $encryptType, $attributes, $content);
                
                if ($encryptType === 'comment') {
                    return self::processCommentEncrypt($attributes, $content, $widget, $options, $blockId);
                } elseif ($encryptType === 'password') {
                    return self::processPasswordEncrypt($attributes, $content, $widget, $options, $blockId);
                }
                
                return $matches[0];
            },
            $text
        );
        
        return $text;
    }
    
    /**
     * 为特权用户（管理员/作者）直接显示隐藏内容
     * 
     * @param string $text 文本内容
     * @return string
     */
    private static function showDecryptedForPrivileged($text)
    {
        // 为管理员和作者直接打开details并标记为已显示
        return preg_replace(
            '/<details([^>]*hide-content[^>]*)>/i',
            '<details$1 open data-decrypted="true">',
            $text
        );
    }
    
    /**
     * 处理评论后可见的隐藏内容
     */
    private static function processCommentEncrypt($attributes, $content, $widget, $options, $blockId)
    {
        // 检查用户是否已评论
        $isCommented = self::checkCommented($widget->cid);
        
        // 清理内容：去除首尾换行和空白
        $cleanContent = trim($content);
        
        // 添加 block_id 属性
        $newAttributes = self::upsertAttribute($attributes, 'data-block-id', $blockId);
        
        if ($isCommented) {
            // 已评论，直接显示内容并标记
            $newAttributes = self::upsertAttribute($newAttributes, 'data-decrypted', 'true');
            return '<details' . $newAttributes . '>' . $cleanContent . '</details>';
        } else {
            // 未评论，返回空标签（不需要加密，前端通过后端API验证评论状态）
            return '<details' . $newAttributes . '></details>';
        }
    }
    
    /**
     * 处理密码可见的隐藏内容
     */
    private static function processPasswordEncrypt($attributes, $content, $widget, $options, $blockId)
    {
        // 清理内容：去除首尾换行和空白
        $cleanContent = trim($content);
        
        // 提取密码（仅用于密钥派生，不输出到前端）
        $correctPassword = '';
        if (preg_match('/data-key="([^"]+)"/i', $attributes, $keyMatch)) {
            $correctPassword = $keyMatch[1];
        }

        // 预加密并仅输出埋点（清空 data-key 和明文内容）
        $encrypted = self::encryptContentForBlock($cleanContent, $widget->cid, $blockId, $correctPassword);

        $attributes = preg_replace('/\sdata-key="[^\"]*"/i', ' data-key=""', $attributes);
        $newAttributes = self::upsertAttribute($attributes, 'data-block-id', $blockId);
        $newAttributes = self::upsertAttribute($newAttributes, 'data-encrypted', $encrypted);

        return '<details' . $newAttributes . '></details>';
    }
    
    /**
     * 检查用户是否已评论
     * 
     * @param int $cid 文章ID
     * @return bool
     */
    private static function checkCommented($cid)
    {
        // 兼容登录用户与游客邮箱两种场景：
        // - 登录：优先使用 authorId；同时可使用用户邮箱匹配
        // - 未登录：使用 Cookie 邮箱匹配
        $user = null;
        try {
            $user = Typecho_Widget::widget('Widget_User');
        } catch (Exception $e) {
            $user = null;
        }

        $hasLogin = $user && $user->hasLogin();
        $userId = $hasLogin ? intval($user->uid) : 0;
        $userMail = ($hasLogin && isset($user->mail)) ? trim($user->mail) : '';
        $cookieMail = Typecho_Cookie::get('__typecho_remember_mail');
        $mailToUse = $cookieMail ? $cookieMail : $userMail;

        // 若既无登录信息也无邮箱，直接认为未评论
        if (!$hasLogin && !$mailToUse) {
            return false;
        }

        try {
            $db = Typecho_Db::get();
            $select = $db->select()
                ->from('table.comments')
                ->where('cid = ?', $cid)
                ->where('status = ?', 'approved')
                ->limit(1);

            if ($hasLogin && $mailToUse) {
                // 登录且有邮箱：authorId 或 mail 命中其一即可
                $select->where('(authorId = ? OR mail = ?)', $userId, $mailToUse);
            } elseif ($hasLogin) {
                // 仅登录：用 authorId 匹配
                $select->where('authorId = ?', $userId);
            } else {
                // 仅邮箱（游客）：用 mail 匹配
                $select->where('mail = ?', $mailToUse);
            }

            $comments = $db->fetchAll($select);
            return !empty($comments);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取默认CSS样式
     * 
     * @return string
     */
    public static function getDefaultStyle()
    {
        $path = dirname(__FILE__) . '/assets/hide-content.css';
        if (!is_readable($path)) {
            $path = dirname(__FILE__) . '/assets/hide-content.min.css';
        }
        if (is_readable($path)) {
            $css = @file_get_contents($path);
            if ($css !== false) {
                return $css;
            }
        }
        return '';
    }

    /**
     * 在属性字符串中插入或更新指定属性
     */
    private static function upsertAttribute($attributes, $name, $value)
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '="[^"]*"/i', $attributes)) {
            return preg_replace('/\s' . preg_quote($name, '/') . '="[^"]*"/i', ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"', $attributes);
        }
        return $attributes . ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }

    /**
     * 为指定块加密内容（AES-256-CBC + HMAC-SHA256）
     */
    private static function encryptContentForBlock($plain, $cid, $blockId, $password)
    {
        $material = $cid . '|' . $blockId . '|' . $password;
        $kdfSalt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $material, $kdfSalt, 100000, 32, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }
        $mac = hash_hmac('sha256', $iv . $kdfSalt . $cipher, $key, true);
        // 版本(1B=0x01) | iv(16) | kdfSalt(16) | mac(32) | cipher
        $payload = chr(1) . $iv . $kdfSalt . $mac . $cipher;
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * 规范化 attributes 串，移除我们注入的临时属性并压缩空白
     */
    private static function normalizeAttributesForFingerprint($attributes)
    {
        $normalized = preg_replace('/\sdata-(encrypted|block-id)="[^"]*"/i', '', $attributes);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        return $normalized;
    }

    /**
     * 计算隐藏块指纹（基于类型、规范化属性和内容）
     */
    private static function computeBlockFingerprint($encryptType, $attributes, $content)
    {
        $normAttr = self::normalizeAttributesForFingerprint($attributes);
        // 仅基于类型与规范化属性，避免受内容渲染差异影响
        return sha1($encryptType . '|' . md5($normAttr));
    }

    /**
     * 获取稳定的 block_id：同一 session 内相同指纹分配固定序号
     */
    private static function getStableBlockId($cid, $encryptType, $attributes, $content)
    {
        // 无状态可重现的指纹ID，避免依赖 session
        $fp = self::computeBlockFingerprint($encryptType, $attributes, $content);
        $suffix = substr(sha1($cid . '|' . $fp), 0, 12);
        return 'hc_' . $encryptType . '_' . $suffix;
    }
}
