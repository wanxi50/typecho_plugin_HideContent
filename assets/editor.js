/**
 * HideContent 编辑器增强
 * 提供插入隐藏内容的按钮和模板（评论后可见 / 密码可见）
 */

(function($) {
    'use strict';
    
    // 预设模板定义
    const templates = {
        comment: {
            empty: {
                name: '评论可见',
                content: `<details hide-content="comment">
这里填写内容
</details>`
            }
        },
        password: {
            empty: {
                name: '密码可见',
                content: `<details hide-content="password" data-key="YOUR_PASSWORD">
这里填写内容
</details>`,
                needPassword: true,
                passwordPlaceholder: 'YOUR_PASSWORD'
            }
        }
    };
    
    // 初始化编辑器按钮
    function initEditorButton() {
        const $buttonRow = $('#wmd-button-row');
        if (!$buttonRow.length) {
            return;
        }
        
        // 添加主按钮到工具栏（使用相对定位，自动排列）
        // SVG锁图标：简洁的闭合锁设计
        const buttonHtml = `
            <li class="wmd-spacer" id="wmd-spacer-hc"></li>
            <li class="wmd-button" id="wmd-hc-button" title="插入隐藏内容">
                <span class="hc-lock-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm9 14H6V10h12v10zm-6-3c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"/>
                    </svg>
                </span>
            </li>
        `;
        
        $buttonRow.append(buttonHtml);
        
        // 创建菜单容器（插入到body，便于定位）
        $('body').append(`
            <div id="hc-menu-container" style="display: none; position: absolute; z-index: 10000;">
                <!-- 一级菜单 -->
                <div id="hc-main-menu" class="hc-menu-panel">
                    <div class="hc-menu-item" data-type="comment">评论可见</div>
                    <div class="hc-menu-item" data-type="password">密码可见</div>
                </div>
                
            </div>
        `);
        
        // 添加菜单样式
        addMenuStyles();
        
        // 绑定事件
        bindEvents();
    }
    
    // 添加菜单样式
    function addMenuStyles() {
        const styles = `
            <style id="hc-menu-styles">
                /* 按钮间隔 */
                #wmd-spacer-hc {
                    width: 1px;
                    height: 20px;
                    margin: 0 14px 0 0;
                    background: #ccc;
                    display: inline-block;
                }
                
                /* 按钮样式 - 与Typecho原生按钮保持一致 */
                #wmd-hc-button {
                    display: inline-block;
                    list-style: none;
                    cursor: pointer;
                }
                
                #wmd-hc-button span.hc-lock-icon {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    cursor: pointer;
                }
                
                /* SVG锁图标样式 */
                #wmd-hc-button span.hc-lock-icon svg {
                    width: 16px;
                    height: 16px;
                    vertical-align: middle;
                    fill: #999;
                    transition: fill 0.2s;
                }
                
                #wmd-hc-button:hover span.hc-lock-icon svg {
                    fill: #666;
                }
                
                #wmd-hc-button:active span.hc-lock-icon svg {
                    fill: #333;
                }
                
                /* 菜单面板样式 */
                .hc-menu-panel {
                    background: white;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                    min-width: 140px;
                    padding: 4px 0;
                }
                
                .hc-menu-item {
                    padding: 10px 16px;
                    cursor: pointer;
                    transition: background 0.2s;
                    font-size: 13px;
                    color: #333;
                    user-select: none;
                }
                
                .hc-menu-item:hover {
                    background: #f5f5f5;
                }
            </style>
        `;
        
        if (!$('#hc-menu-styles').length) {
            $('head').append(styles);
        }
    }
    
    // 绑定事件
    function bindEvents() {
        // 点击主按钮显示/隐藏菜单
        $('#wmd-hc-button').on('click', function(e) {
            e.stopPropagation();
            
            const $menuContainer = $('#hc-menu-container');
            
            if ($menuContainer.is(':visible')) {
                hideAllMenus();
            } else {
                // 定位菜单
                const offset = $(this).offset();
                $menuContainer.css({
                    top: offset.top + $(this).outerHeight() + 5,
                    left: offset.left
                });
                
                // 显示主菜单
                $('#hc-main-menu').show();
                $menuContainer.show();
            }
        });
        
        // 点击菜单项插入模板
        $('#hc-main-menu .hc-menu-item').on('click', function(e) {
            e.stopPropagation();
            const type = $(this).data('type');
            insertTemplate(type, 'empty');
            hideAllMenus();
        });
        
        // 点击其他地方关闭菜单
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#hc-menu-container, #wmd-hc-button').length) {
                hideAllMenus();
            }
        });
    }
    
    // 隐藏所有菜单
    function hideAllMenus() {
        $('#hc-menu-container').hide();
        $('#hc-main-menu').hide();
    }
    
    // 插入模板
    function insertTemplate(encryptType, templateType) {
        const template = templates[encryptType][templateType];
        if (!template) {
            return;
        }
        
        let content = template.content;
        
        // 如果是密码可见类型，先弹窗输入密码
        if (template.needPassword) {
            const password = prompt('请输入访问密码（明文密码，将保存在文章中便于您查看和管理）:');
            if (!password) return;
            
            // 替换模板中的密码占位符（保存明文密码）
            content = content.replace(/YOUR_PASSWORD/g, password);
        }
        
        insertToEditor(content);
    }
    
    // 插入内容到编辑器
    function insertToEditor(content) {
        const textarea = document.getElementById('text');
        if (!textarea) {
            return;
        }
        
        // 获取当前光标位置和选中文本
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selectedText = textarea.value.substring(start, end);
        
        // 如果有选中文本，用选中的文本替换模板中的"这里填写内容"
        if (selectedText) {
            content = content.replace('这里填写内容', selectedText);
        }
        
        // 获取当前文本内容
        const beforeText = textarea.value.substring(0, start);
        const afterText = textarea.value.substring(end);
        
        // 插入新内容
        textarea.value = beforeText + content + afterText;
        
        // 设置光标位置到插入内容之后
        const newPosition = start + content.length;
        textarea.selectionStart = newPosition;
        textarea.selectionEnd = newPosition;
        
        // 聚焦到编辑器
        textarea.focus();
        
        // 触发input事件，确保Typecho检测到内容变化
        const event = new Event('input', { bubbles: true });
        textarea.dispatchEvent(event);
    }
    
    // 页面加载完成后初始化
    $(document).ready(function() {
        // 延迟初始化，确保编辑器DOM已加载
        setTimeout(function() {
            initEditorButton();
        }, 500);
    });
    
})(typeof jQuery !== 'undefined' ? jQuery : window.$);
