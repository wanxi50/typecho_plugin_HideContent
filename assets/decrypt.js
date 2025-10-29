/**
 * HideContent 前端验证和显示逻辑
 * 支持AJAX异步验证和模板美化
 */

(function() {
    'use strict';
    
    // 配置
    const config = {
        actionUrl: window.TypechoHideContent?.actionUrl || '/action/hide-content?do=decrypt',
        cid: window.TypechoHideContent?.cid || 0
    };
    
    // LocalStorage 键名（记录是否解过密，仅用于评论类型）
    const STORAGE_KEY = 'typecho_hidecontent_decrypted';
    // SessionStorage 前端缓存（仅当前标签页有效，适用于密码类型解密后的缓存展示）
    const SESSION_CACHE_PREFIX = 'hc_cache_';
    
    // 获取已解密的块ID列表
    function getDecryptedBlocks() {
        try {
            const data = localStorage.getItem(STORAGE_KEY);
            return data ? JSON.parse(data) : {};
        } catch (e) {
            return {};
        }
    }
    
    // 保存已解密的块ID
    function saveDecryptedBlock(cid, blockId) {
        try {
            const decrypted = getDecryptedBlocks();
            if (!decrypted[cid]) {
                decrypted[cid] = [];
            }
            if (!decrypted[cid].includes(blockId)) {
                decrypted[cid].push(blockId);
            }
            localStorage.setItem(STORAGE_KEY, JSON.stringify(decrypted));
        } catch (e) {
            console.warn('无法保存解密状态:', e);
        }
    }
    
    // 检查块是否已解密
    function isBlockDecrypted(cid, blockId) {
        const decrypted = getDecryptedBlocks();
        return decrypted[cid] && decrypted[cid].includes(blockId);
    }

    // 读取会话缓存中的明文内容（仅当前会话，避免长期持久化）
    function getCachedContent(cid, blockId) {
        try {
            return sessionStorage.getItem(SESSION_CACHE_PREFIX + cid + '_' + blockId) || '';
        } catch (_) { return ''; }
    }

    // 写入会话缓存（仅密码类型在解密成功后写入）
    function setCachedContent(cid, blockId, content) {
        try {
            sessionStorage.setItem(SESSION_CACHE_PREFIX + cid + '_' + blockId, String(content || ''));
        } catch (_) { /* ignore */ }
    }
    
    // 初始化
    function init() {
        processEncryptedBlocks();
        // 页面加载完成后，自动尝试显示所有评论后可见的内容
        autoDecryptOnLoad();
    }

    
    // 处理所有隐藏内容块
    function processEncryptedBlocks() {
        const blocks = document.querySelectorAll('details[hide-content]');
        
        blocks.forEach((block, index) => {
            const encryptType = block.getAttribute('hide-content');
            const existsId = block.getAttribute('data-block-id');
            const blockId = existsId || `hc_${encryptType}_${index}`;
            const encryptedPayload = block.getAttribute('data-encrypted') || '';
            
            // 检查是否为已授权用户（管理员/作者/已评论）- 后端标记了 data-decrypted
            const isAuthorized = block.hasAttribute('data-decrypted') && block.getAttribute('data-decrypted') === 'true';
            
            if (isAuthorized) {
                // 已授权，直接显示内容（评论已评论/管理员/作者）
                const newDiv = document.createElement('div');
                newDiv.setAttribute('hide-content', encryptType);
                newDiv.setAttribute('data-block-id', blockId);
                newDiv.className = 'hc-content';
                newDiv.innerHTML = block.innerHTML.trim();
                
                // 替换DOM节点
                block.parentNode.replaceChild(newDiv, block);
                return;
            }
            
            // 将details标签完全替换为div，彻底移除折叠功能
            const newDiv = document.createElement('div');
            newDiv.setAttribute('hide-content', encryptType);
            newDiv.setAttribute('data-block-id', blockId);
            if (encryptedPayload) {
                newDiv.setAttribute('data-encrypted', encryptedPayload);
            }
            
            // 替换DOM节点
            block.parentNode.replaceChild(newDiv, block);
            
            // 检查是否已在本地存储/会话缓存中解密过
            const wasPreviouslyDecrypted = isBlockDecrypted(config.cid, blockId);
            const cachedPlain = getCachedContent(config.cid, blockId);
            
            if (cachedPlain) {
                // 直接使用缓存明文（仅当前会话）
                const contentHtml = String(cachedPlain).trim();
                const replaced = document.createElement('div');
                replaced.setAttribute('hide-content', encryptType);
                replaced.setAttribute('data-block-id', blockId);
                replaced.className = 'hc-content';
                replaced.innerHTML = contentHtml;
                newDiv.parentNode.replaceChild(replaced, newDiv);
            } else if (wasPreviouslyDecrypted) {
                // 已解密过，自动尝试解密（静默模式）
                if (encryptType === 'comment') {
                    handleCommentDecrypt(newDiv, true);
                } else if (encryptType === 'password') {
                    // 密码类型：提示输入，但若会话缓存存在会直接显示（上面分支）
                    renderPasswordUI(newDiv);
                }
            } else {
                // 渲染UI
                if (encryptType === 'comment') {
                    renderCommentUI(newDiv);
                } else if (encryptType === 'password') {
                    renderPasswordUI(newDiv);
                }
            }
        });
    }
    
    // 渲染评论可见UI
    function renderCommentUI(block) {
        // 获取配置的提示文字
        const commentNotice = window.TypechoHideContent?.commentNotice || '请发表评论后查看';
        
        const html = '<div class="hc-lock-container" data-type="comment" data-block-id="' + block.getAttribute('data-block-id') + '">' +
            '<div class="hc-lock-icon"></div>' +
            '<span class="hc-lock-text">' + commentNotice + '</span>' +
            '<span class="hc-comment-hint">发表评论即可查看</span>' +
            '</div>';
        
        block.innerHTML = html;
        
        // 绑定点击事件
        const lockContainer = block.querySelector('.hc-lock-container');
        lockContainer.addEventListener('click', function() {
            handleCommentDecrypt(block);
        });
    }
    
    // 渲染密码可见UI
    function renderPasswordUI(block) {
        // 获取配置的提示文字
        const passwordNotice = window.TypechoHideContent?.passwordNotice || '请输入密码后查看';
        
        const html = '<div class="hc-lock-container" data-type="password" data-block-id="' + block.getAttribute('data-block-id') + '">' +
            '<div class="hc-lock-icon"></div>' +
            '<span class="hc-lock-text">' + passwordNotice + '</span>' +
            '<div class="hc-input-wrapper">' +
            '<input type="password" class="hc-password-input" placeholder="请输入密码">' +
            '<button class="hc-submit-btn">确认</button>' +
            '</div>' +
            '</div>';
        
        block.innerHTML = html;
        
        // 绑定事件
        const input = block.querySelector('.hc-password-input');
        const button = block.querySelector('.hc-submit-btn');
        const lockContainer = block.querySelector('.hc-lock-container');
        
        button.addEventListener('click', function() {
            handlePasswordDecrypt(block, input, button, lockContainer);
        });
        
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                button.click();
            }
        });
        
        // 自动聚焦
        setTimeout(() => input.focus(), 100);
    }
    
    // 处理评论后可见的显示
    // silent: 静默模式，失败时不抖动（用于自动尝试显示）
    function handleCommentDecrypt(block, silent = false) {
        const blockId = block.getAttribute('data-block-id');
        const lockContainer = block.querySelector('.hc-lock-container');
        
        // 防止重复点击
        if (lockContainer.classList.contains('hc-processing')) {
            return;
        }
        
        lockContainer.classList.add('hc-processing');
        lockContainer.style.opacity = '0.5';
        lockContainer.style.pointerEvents = 'none';
        
        // 发送AJAX请求（仅传递密文）
        const encrypted = block.getAttribute('data-encrypted');
        sendDecryptRequest({
            type: 'comment',
            block_id: blockId,
            content: encrypted
        }).then(response => {
            if (response.success) {
                showDecryptedContent(lockContainer, response.content);
            } else {
                // 验证失败（未评论）
                lockContainer.classList.remove('hc-processing');
                lockContainer.style.opacity = '1';
                lockContainer.style.pointerEvents = 'auto';
                // 仅在非静默模式下显示错误提示
                if (!silent) {
                    const code = Number(response.code);
                    if (code === 2002) {
                        const configured = window.TypechoHideContent?.commentErrorHtml;
                        if (configured) {
                            showError(lockContainer, configured, true);
                        } else {
                            showError(lockContainer, response.message || '请先评论后查看', false);
                        }
                    } else {
                        showError(lockContainer, response.message || '操作失败', false);
                    }
                }
            }
        }).catch(error => {
            // 请求失败
            lockContainer.classList.remove('hc-processing');
            lockContainer.style.opacity = '1';
            lockContainer.style.pointerEvents = 'auto';
            if (!silent) {
                showError(lockContainer, '请求失败，请稍后重试', false);
            }
        });
    }
    
    // 处理密码验证和显示（仅传递三要素）
    function handlePasswordDecrypt(block, input, button, lockContainer) {
        const password = input.value.trim();
        if (!password) {
            return;
        }

        button.disabled = true;
        button.textContent = '验证中...';

        const blockId = block.getAttribute('data-block-id');
        const encrypted = block.getAttribute('data-encrypted');

        if (!encrypted) {
            button.disabled = false;
            button.textContent = '确认';
            return;
        }

        sendDecryptRequest({
            type: 'password',
            block_id: blockId,
            content: encrypted,
            password: password
        }).then(response => {
            if (response.success) {
                showDecryptedContent(lockContainer, response.content);
            } else {
                button.disabled = false;
                button.textContent = '确认';
                input.value = '';
                input.style.borderColor = '#dc3545';
                setTimeout(() => {
                    input.style.borderColor = '#d0d0d0';
                }, 400);
                input.focus();
                const code = Number(response.code);
                if (code === 2001) {
                    const configured = window.TypechoHideContent?.passwordErrorHtml;
                    if (configured) {
                        showError(lockContainer, configured, true);
                    } else {
                        showError(lockContainer, response.message || '密码错误', false);
                    }
                } else {
                    showError(lockContainer, response.message || '操作失败', false);
                }
            }
        }).catch(() => {
            button.disabled = false;
            button.textContent = '确认';
            showError(lockContainer, '请求失败，请稍后重试', false);
        });
    }
    
    // 显示已验证通过的隐藏内容（统一函数，直接替换整个block）
    function showDecryptedContent(lockContainer, content) {
        // 获取block并保存解密状态
        const block = lockContainer.parentElement;
        if (!block) return;
        
        const blockId = block.getAttribute('data-block-id');
        if (blockId) {
            saveDecryptedBlock(config.cid, blockId);
            // 写入会话缓存（避免刷新后再次输入密码）。评论类型也可复用，但主要用于密码类型
            setCachedContent(config.cid, blockId, content);
        }
        
        // 淡出
        lockContainer.style.opacity = '0';
        
        setTimeout(() => {
            // 创建新的内容容器，替换整个block
            const newDiv = document.createElement('div');
            const encryptType = block.getAttribute('hide-content');
            if (encryptType) {
                newDiv.setAttribute('hide-content', encryptType);
            }
            if (blockId) {
                newDiv.setAttribute('data-block-id', blockId);
            }
            newDiv.className = 'hc-content';
            newDiv.innerHTML = String(content || '').trim();
            
            // 替换DOM节点
            block.parentNode.replaceChild(newDiv, block);
            
            // 绑定复制按钮
            bindCopyButtons(newDiv);
            
        }, 300);
    }

    // 显示错误提示（在锁容器下方）
    function showError(lockContainer, message, allowHtml = false) {
        const parent = lockContainer.parentElement;
        if (!parent) return;
        let errorEl = parent.querySelector('.hc-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'hc-error';
            parent.appendChild(errorEl);
        }
        if (allowHtml) {
            errorEl.innerHTML = String(message || '操作失败');
        } else {
            errorEl.textContent = String(message || '操作失败');
        }
        errorEl.style.display = 'block';
    }
    
    // 发送验证请求
    function sendDecryptRequest(data) {
        data.cid = config.cid;
        
        return fetch(config.actionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(data)
        }).then(response => {
            // 先获取原始文本
            return response.text().then(text => {
                // 尝试解析JSON
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('服务器返回格式错误');
                }
            });
        });
    }
    
    // 绑定复制按钮
    function bindCopyButtons(container) {
        const buttons = container.querySelectorAll('.hc-copy-btn');
        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const text = this.getAttribute('data-copy');
                copyToClipboard(text);
                
                const originalText = this.textContent;
                this.textContent = '已复制';
                setTimeout(() => {
                    this.textContent = originalText;
                }, 2000);
            });
        });
    }
    
    // 复制到剪贴板
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            // 降级方案
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
    }
    
    // 监听评论提交成功事件
    function watchCommentSubmit() {
        // 方法1: 监听评论表单提交
        const commentForm = document.querySelector('#comment-form, .comment-form, form[action*="comment"]');
        if (commentForm) {
            commentForm.addEventListener('submit', function(e) {
                // 延迟执行，等待评论提交完成
                setTimeout(() => {
                    autoDecryptAfterComment();
                }, 2000);
            });
        }
        
        // 方法2: 监听评论列表 DOM 变化（检测新评论添加）
        const commentList = document.querySelector('.comment-list, #comments, .comments');
        if (commentList) {
            let decryptTimer = null;
            const observer = new MutationObserver(function(mutations) {
                // 防抖：避免短时间内重复触发
                if (decryptTimer) {
                    clearTimeout(decryptTimer);
                }
                
                decryptTimer = setTimeout(() => {
                    const hasNewNodes = mutations.some(mutation => mutation.addedNodes.length > 0);
                    if (hasNewNodes) {
                        autoDecryptAfterComment();
                    }
                }, 800); // 800ms防抖延迟
            });
            
            observer.observe(commentList, {
                childList: true,
                subtree: true
            });
        }
        
        // 方法3: 拦截 XMLHttpRequest（兼容老式 AJAX）
        if (window.XMLHttpRequest) {
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            
            XMLHttpRequest.prototype.open = function(method, url) {
                this._url = url;
                return originalOpen.apply(this, arguments);
            };
            
            XMLHttpRequest.prototype.send = function() {
                this.addEventListener('load', function() {
                    if (this._url && (this._url.includes('comment') || this._url.includes('feedback'))) {
                        if (this.status === 200 && this.responseText) {
                            if (this.responseText.includes('success') || this.responseText.includes('提交成功')) {
                                setTimeout(() => {
                                    autoDecryptAfterComment();
                                }, 1000);
                            }
                        }
                    }
                });
                return originalSend.apply(this, arguments);
            };
        }
    }
    
    // 页面加载时自动尝试显示评论后可见的内容
    function autoDecryptOnLoad() {
        // 找到所有评论后可见的锁定容器
        const commentLocks = document.querySelectorAll('.hc-comment-lock');
        
        commentLocks.forEach(lockContainer => {
            const block = lockContainer.parentElement;
            const contentDiv = block.querySelector('.hc-decrypted-content');
            
            // 如果已经显示，跳过
            if (contentDiv && contentDiv.classList.contains('show')) {
                return;
            }
            
            // 自动尝试显示（静默模式 = true，失败不抖动）
            handleCommentDecrypt(block, true);
        });
    }
    
    // 评论后自动尝试显示所有评论后可见的内容
    function autoDecryptAfterComment() {
        // 延迟一下，确保评论已经保存到数据库
        setTimeout(() => {
            autoDecryptOnLoad();
        }, 500);
    }
    
    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            init();
            watchCommentSubmit();
        });
    } else {
        init();
        watchCommentSubmit();
    }
    
})();
