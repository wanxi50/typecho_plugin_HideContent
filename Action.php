<?php
if (!defined('__TYPECHO_ROOT_DIR__'))
    exit;

/**
 * 隐藏内容 AJAX 解密处理器
 * 
 * @package HideContent
 * @author 笨蛋五十 (wanxi50)
 */
class HideContent_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /**
     * 接口返回码说明（code）：
     * 成功：0
     * 参数/权限类：
     *   1001 方法不被允许（METHOD_NOT_ALLOWED）
     *   1002 同源校验失败（ORIGIN_INVALID）
     *   1003 参数错误（PARAM_ERROR）
     *   1004 文章不存在（NOT_FOUND）
     *   1005 类型错误（TYPE_ERROR）
     *   1006 隐藏块不存在（BLOCK_NOT_FOUND）
     * 业务类：
     *   2001 密码错误（PASSWORD_ERROR）
     *   2002 需要评论后查看（COMMENT_REQUIRED）
     * 解密类：
     *   3001 解密失败（DECRYPT_FAIL）
     * 限流类：
     *   4001 频率限制（RATE_LIMIT）
     * 服务端：
     *   5001 服务器错误（SERVER_ERROR）
     */
    /**
     * 执行AJAX请求
     */
    public function action()
    {
        // 默认调用decrypt方法
        $this->decrypt();
    }

    /**
     * 处理显示隐藏内容的请求
     * 
     * @return void
     */
    public function decrypt()
    {
        // 设置响应头
        header('Content-Type: application/json');

        try {
            // 仅允许 POST
            if (!$this->request->isPost()) {
                echo json_encode([
                    'success' => false,
                    'message' => '非法请求方法',
                    'code' => 1001
                ]);
                exit;
            }

            // 简易 CSRF 校验：校验 Origin/Referer 同源
            if (!$this->checkSameOrigin()) {
                echo json_encode([
                    'success' => false,
                    'message' => '来源校验失败',
                    'code' => 1002
                ]);
                exit;
            }

            // 获取请求参数
            $cid = $this->request->get('cid');
            $type = $this->request->get('type'); // 隐藏类型：comment（评论后可见）或 password（密码可见）
            $encrypted = $this->request->get('content'); // 前端传回的密文（data-encrypted）
            $userPassword = $this->request->get('password'); // 用户输入的明文密码（或MD5，兼容旧逻辑）
            $blockId = $this->request->get('block_id'); // 内容块唯一ID（如 hc_password_0）
            $ip = $this->request->getIp();

            // 验证必填参数
            if (empty($cid) || empty($type) || empty($encrypted) || empty($blockId)) {
                echo json_encode([
                    'success' => false,
                    'message' => '参数错误',
                    'code' => 1003
                ]);
                exit;
            }

            // 参数校验与规范化
            $cid = intval($cid);
            if ($cid <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误', 'code' => 1003]);
                exit;
            }
            if (!in_array($type, ['comment', 'password'], true)) {
                echo json_encode(['success' => false, 'message' => '参数错误', 'code' => 1003]);
                exit;
            }
            if (!preg_match('/^hc_(comment|password)_[a-f0-9]{12}$/i', $blockId)) {
                echo json_encode(['success' => false, 'message' => '参数错误', 'code' => 1003]);
                exit;
            }
            if (!preg_match('/^[A-Za-z0-9\-_]+$/', $encrypted)) {
                echo json_encode(['success' => false, 'message' => '参数错误', 'code' => 1003]);
                exit;
            }
            if ($type === 'password') {
                if ($userPassword === null || $userPassword === '' || strlen($userPassword) > 128) {
                    echo json_encode(['success' => false, 'message' => '参数错误', 'code' => 1003]);
                    exit;
                }
            }

            // 获取文章
            $db = Typecho_Db::get();
            $post = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ?', $cid)->limit(1));

            if (!$post) {
                echo json_encode([
                    'success' => false,
                    'message' => '文章不存在',
                    'code' => 1004
                ]);
                exit;
            }

            // 基于文章原文解析权威密码（仅密码可见类型需要）
            $correctPassword = '';
            if ($type === 'password') {
                // 读取频率限制配置（0-600秒；0 表示不限制）
                $options = Typecho_Widget::widget('Widget_Options')->plugin('HideContent');
                $rlSeconds = 60;
                if (isset($options->rateLimitSeconds)) {
                    $val = intval($options->rateLimitSeconds);
                    if ($val < 0) $val = 0;
                    if ($val > 600) $val = 600;
                    $rlSeconds = $val;
                }
                // 简单限流：同一IP X 秒内仅允许一次密码提交
                if ($rlSeconds > 0 && !$this->checkIpRateLimit($ip, $rlSeconds)) {
                    echo json_encode([
                        'success' => false,
                        'message' => '操作过于频繁，请稍后再试',
                        'code' => 4001
                    ]);
                    exit;
                }
                $correctPassword = $this->extractCorrectPasswordFromPost($post, $blockId);
                if ($correctPassword === null) {
                    echo json_encode([
                        'success' => false,
                        'message' => '未找到对应的隐藏块',
                        'code' => 1006
                    ]);
                    exit;
                }
                // 用户密码比对（兼容旧逻辑：若传来32位hex视为MD5对比）
                if (!$this->verifyUserPassword($userPassword, $correctPassword)) {
                    echo json_encode([
                        'success' => false,
                        'message' => '密码错误',
                        'code' => 2001
                    ]);
                    exit;
                }
            } elseif ($type === 'comment') {
                // 评论权限校验
                if (!$this->checkCommented($post['cid'])) {
                    echo json_encode([
                        'success' => false,
                        'message' => '请评论后查看',
                        'code' => 2002
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => '类型错误',
                    'code' => 1005
                ]);
                exit;
            }

            // 解密密文
            $plain = $this->decryptPayload($encrypted, $post['cid'], $blockId, $correctPassword);
            if ($plain === null) {
                echo json_encode([
                    'success' => false,
                    'message' => '解密失败',
                    'code' => 3001
                ]);
                exit;
            }

            // 提取details内真实内容（向后兼容：原始明文通常包含完整details）
            $decryptedContent = $this->extractContent($plain);

            echo json_encode([
                'success' => true,
                'content' => $decryptedContent,
                'message' => '内容已显示',
                'code' => 0
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => '服务器错误，请稍后重试',
                'code' => 5001
            ]);
            exit;
        }
    }

    /**
     * 提取details标签内的真实内容
     * 
     * @param string $html 原始HTML内容
     * @return string
     */
    private function extractContent($html)
    {
        // 先解码HTML实体
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 移除details标签，只保留内部内容
        $html = preg_replace('/<details[^>]*>/i', '', $html);
        $html = preg_replace('/<\/details>/i', '', $html);
        
        // 清理多余的空标签和换行
        $html = preg_replace('/<p>\s*<\/p>/i', '', $html); // 移除空的 <p> 标签
        $html = preg_replace('/<br>\s*<br>/i', '<br>', $html); // 移除连续的 <br>
        $html = preg_replace('/^\s*<br>\s*/i', '', $html); // 移除开头的 <br>
        $html = preg_replace('/\s*<br>\s*$/i', '', $html); // 移除结尾的 <br>

        return trim($html);
    }

    /**
     * 检查权限
     * 
     * @param array $post 文章数组
     * @param string $type 隐藏类型（comment 或 password）
     * @param string $password 用户输入的密码（MD5）
     * @param string $blockId 内容块ID
     * @return bool
     */
    private function checkPermission($post, $type, $password, $blockId)
    {
        // 已弃用
        return false;
    }

    /**
     * 检查用户是否已评论
     * 
     * @param int $cid 文章ID
     * @return bool
     */
    private function checkCommented($cid)
    {
        // 兼容登录用户与游客邮箱两种场景
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
                $select->where('(authorId = ? OR mail = ?)', $userId, $mailToUse);
            } elseif ($hasLogin) {
                $select->where('authorId = ?', $userId);
            } else {
                $select->where('mail = ?', $mailToUse);
            }

            $comments = $db->fetchAll($select);
            return !empty($comments);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 解析文章正文中与 blockId 对应的正确密码（基于稳定指纹+会话映射）
     */
    private function extractCorrectPasswordFromPost($post, $blockId)
    {
        if (!isset($post['text'])) {
            return null;
        }
        $html = $post['text'];
        $pattern = '/<details([^>]*hide-content=\"password\"[^>]*)>(.*?)<\/details>/is';
        if (!preg_match_all($pattern, $html, $all, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($all as $match) {
            $attributes = $match[1];
            $inner = $match[2];
            $pwd = '';
            if (preg_match('/data-key=\"([^\"]*)\"/i', $attributes, $km)) {
                $pwd = $km[1];
            }
            $stableId = $this->getStableBlockId($post['cid'], 'password', $attributes, $inner);
            if ($stableId === $blockId) {
                return $pwd;
            }
        }
        return null;
    }

    private function normalizeAttributesForFingerprint($attributes)
    {
        $normalized = preg_replace('/\sdata-(encrypted|block-id)=\"[^\"]*\"/i', '', $attributes);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        return $normalized;
    }

    private function computeBlockFingerprint($encryptType, $attributes, $content)
    {
        $normAttr = $this->normalizeAttributesForFingerprint($attributes);
        // 与渲染侧一致：仅基于类型与规范化属性
        return sha1($encryptType . '|' . md5($normAttr));
    }

    private function getStableBlockId($cid, $encryptType, $attributes, $content)
    {
        $fp = $this->computeBlockFingerprint($encryptType, $attributes, $content);
        $suffix = substr(sha1($cid . '|' . $fp), 0, 12);
        return 'hc_' . $encryptType . '_' . $suffix;
    }

    /**
     * 校验用户输入密码（仅明文）
     */
    private function verifyUserPassword($userPassword, $correctPassword)
    {
        if ($userPassword === null || $userPassword === '') {
            return false;
        }
        return trim($userPassword) === $correctPassword;
    }

    /**
     * 用与渲染相同的规则解密
     */
    private function decryptPayload($encoded, $cid, $blockId, $password)
    {
        $b64 = strtr($encoded, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $payload = base64_decode($b64, true);
        if ($payload === false || strlen($payload) < 1 + 16 + 16 + 32) {
            return null;
        }
        $version = ord($payload[0]);
        if ($version !== 1) {
            return null; // 仅支持v1
        }
        $iv = substr($payload, 1, 16);
        $kdfSalt = substr($payload, 17, 16);
        $mac = substr($payload, 33, 32);
        $cipher = substr($payload, 65);
        $material = $cid . '|' . $blockId . '|' . $password;
        $key = hash_pbkdf2('sha256', $material, $kdfSalt, 100000, 32, true);
        $calcMac = hash_hmac('sha256', $iv . $kdfSalt . $cipher, $key, true);
        if (!hash_equals($mac, $calcMac)) {
            return null;
        }
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return null;
        }
        return $plain;
    }

    // 校验同源：Origin 或 Referer 与站点配置一致
    private function checkSameOrigin()
    {
        $site = Helper::options()->index;
        $siteParts = @parse_url($site);
        $siteHost = isset($siteParts['host']) ? strtolower($siteParts['host']) : '';
        $siteScheme = isset($siteParts['scheme']) ? strtolower($siteParts['scheme']) : '';
        $sitePort = isset($siteParts['port']) ? intval($siteParts['port']) : (($siteScheme === 'https') ? 443 : 80);

        $origin = $this->request->getServer('HTTP_ORIGIN');
        $referer = $this->request->getServer('HTTP_REFERER');
        $header = $origin ?: $referer;
        if (!$header) {
            // 没有头时放行
            return true;
        }
        $hParts = @parse_url($header);
        if (!$hParts) return false;
        $hHost = isset($hParts['host']) ? strtolower($hParts['host']) : '';
        $hScheme = isset($hParts['scheme']) ? strtolower($hParts['scheme']) : '';
        $hPort = isset($hParts['port']) ? intval($hParts['port']) : (($hScheme === 'https') ? 443 : 80);

        return ($siteHost === $hHost) && ($siteScheme === $hScheme) && ($sitePort === $hPort);
    }

    // 简单IP限流：在系统临时目录下为每个IP创建时间戳文件，限制间隔秒数
    private function checkIpRateLimit($ip, $intervalSeconds)
    {
        if (!$ip) return true;
        $dir = sys_get_temp_dir();
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hc_rl_' . md5($ip);
        // 概率性清理过期限流文件，降低目录堆积风险（约每500次触发一次）
        try {
            $rand = function_exists('random_int') ? random_int(1, 500) : mt_rand(1, 500);
            if ($rand === 1) {
                $this->cleanupRateLimitFiles($dir, 86400); // 清理1天前的记录
            }
        } catch (\Throwable $t) {
            // ignore
        }
        if (file_exists($file)) {
            $last = @filemtime($file);
            if ($last && (time() - $last) < $intervalSeconds) {
                return false;
            }
        }
        // 更新时间戳
        @touch($file);
        return true;
    }

    // 清理过期的限流标记文件
    private function cleanupRateLimitFiles($dir, $maxAgeSeconds)
    {
        $pattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hc_rl_*';
        foreach (glob($pattern) as $path) {
            if (is_file($path)) {
                $mtime = @filemtime($path);
                if (!$mtime || (time() - $mtime) > $maxAgeSeconds) {
                    @unlink($path);
                }
            }
        }
    }
}

