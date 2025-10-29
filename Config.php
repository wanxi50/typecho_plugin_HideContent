<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 隐藏内容插件配置面板
 * 
 * @package HideContent
 * @author 笨蛋五十 (wanxi50)
 */
class HideContent_Config
{
    /**
     * 获取插件配置面板
     * 
     * @param Typecho_Widget_Helper_Form $form 配置表单
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<div style="background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #e0e0e0; margin-bottom: 15px;">
        <h2>使用方法</h2>
        <ol>
        <li>评论后可见：
           <ul>
           <li>在编辑器中点击"插入隐藏内容"按钮，选择"评论可见"</li>
           <li>使用 details 标签：&lt;details hide-content="comment"&gt;内容&lt;/details&gt;</li>
           <li>访客必须评论后才能查看隐藏的内容</li>
           </ul>
        </li>
        <li>密码可见：
           <ul>
           <li>在编辑器中点击"插入隐藏内容"按钮，选择"密码可见"模板</li>
           <li>使用 details 标签：&lt;details hide-content="password" data-key="密码"&gt;内容&lt;/details&gt;</li>
           <li>访客需要输入正确的密码才能查看隐藏的内容</li>
           </ul>
        </li>
        </ol>


        <h2>特性</h2>
        <ul>
        <li>支持 AJAX 异步验证，提交评论/密码后，自动显示隐藏内容，无需刷新页面</li>
        <li>管理员和文章作者可直接查看所有隐藏内容</li>
        <li>使用&lt;details&gt;标签，兼容标准 Markdown 语法，无缝迁移文章</li>
        <li>精美的现代化样式，支持完全自定义替换css样式</li>
        </ul>
        
        <h2>设置</h2>
        <ul>
        <li>禁用插件后重新启用即可恢复默认样式。</li>
        <li><strong>启用隐藏功能：</strong>启用后，访客需要评论/输入密码后才能查看隐藏内容。关闭后不再隐藏任何内容，直接展示给所有访客</li>
        <li><strong>管理员/作者直接查看隐藏内容：</strong>启用后，管理员和文章作者无需验证即可查看隐藏内容。禁用后将与普通访客一致，需要评论/输入密码。</li>
        <li><strong>评论后可见提示：</strong>设置访客未评论时显示的提示文字</li>
        <li><strong>密码可见提示：</strong>设置访客未输入密码时显示的提示文字</li>
        <li><strong>评论错误提示：</strong>设置访客未满足“评论可见”条件时显示的错误提示</li>
        <li><strong>密码错误提示：</strong>设置访客输入错误密码时显示的错误提示</li>
        <li><strong>密码尝试频率限制：</strong>设置同一IP在X秒内仅允许一次密码提交，取值0-600；0表示不限制</li>
        <li><strong>自定义样式：</strong>可以自定义隐藏内容的CSS样式，留空则使用默认样式</li>
        </ul>
        </div>';

        // 功能开关：启用/禁用隐藏功能（关闭后直接展示内容）
        $hideEnabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'hideEnabled',
            array('1' => _t('启用'), '0' => _t('关闭')),
            '1',
            _t('启用隐藏功能'),
            _t('注意：关闭后不再隐藏任何内容，直接展示给所有访客')
        );
        $form->addInput($hideEnabled);
        
        // 管理员/作者直通查看开关
        $privilegedBypass = new Typecho_Widget_Helper_Form_Element_Radio(
            'privilegedBypass',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('管理员/作者直接查看隐藏内容'),
            _t('启用后，管理员和文章作者无需验证即可查看隐藏内容。禁用后将与普通访客一致，需要评论/输入密码。')
        );
        $form->addInput($privilegedBypass);


        // 评论可见提示文字设置
        $commentNotice = new Typecho_Widget_Helper_Form_Element_Text(
            'commentNotice', 
            NULL, 
            '<span>请发表评论后查看</span>', 
            _t('评论后可见提示文字（支持HTML）'),
            _t('当访客未评论时显示的提示文字')
        );
        $form->addInput($commentNotice);

        // 密码可见提示文字设置
        $passwordNotice = new Typecho_Widget_Helper_Form_Element_Text(
            'passwordNotice', 
            NULL, 
            '<span>请输入密码后查看</span>', 
            _t('密码可见提示文字（支持HTML）'),
            _t('当访客未输入密码时显示的提示文字')
        );
        $form->addInput($passwordNotice);
        
        // 错误提示（HTML）- 评论错误
        $commentErrorHtml = new Typecho_Widget_Helper_Form_Element_Text(
            'commentErrorHtml', 
            NULL, 
            '<span>请先发表评论后查看</span>', 
            _t('评论错误提示（支持HTML）'),
            _t('当访客未满足“评论可见”条件时显示的错误提示，可填入 HTML')
        );
        $form->addInput($commentErrorHtml);

        // 错误提示（HTML）- 密码错误
        $passwordErrorHtml = new Typecho_Widget_Helper_Form_Element_Text(
            'passwordErrorHtml', 
            NULL, 
            '<span>密码错误，请重试</span>', 
            _t('密码错误提示（支持HTML）'),
            _t('当访客输入错误密码时显示的错误提示，可填入 HTML')
        );
        $form->addInput($passwordErrorHtml);

        
        

		// 密码尝试频率限制（秒）：同一IP X秒内仅允许一次（0-600，0表示不限制）
		$rateLimitSeconds = new Typecho_Widget_Helper_Form_Element_Text(
			'rateLimitSeconds',
			NULL,
			'60',
			_t('密码尝试频率限制（秒）'),
			_t('同一 IP 在 X 秒内仅允许一次密码提交，取值 0-600；0 表示不限制')
		);
		$form->addInput($rateLimitSeconds);

        // 自定义样式设置
        $defaultCss = HideContent_Plugin::getDefaultStyle();
        
        $customStyle = new Typecho_Widget_Helper_Form_Element_Textarea(
            'customStyle', 
            NULL, 
            $defaultCss, 
            _t('自定义样式'),
            _t('可以自定义隐藏内容的CSS样式，留空则使用默认样式')
        );
        $form->addInput($customStyle);
        // 用户选择：不再提供“恢复默认样式”按钮。禁用插件并重新启用即可恢复默认样式。
    }
}

