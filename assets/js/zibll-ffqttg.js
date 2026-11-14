(function ($, window) {
    'use strict';

    var config = window.ZibllFfqttg || {};
    var resourcePrefix = 'zibll_ffqttg_download';

    function fieldLabel(text) {
        return $('<div class="muted-color mb6">').text(text);
    }

    function textInput(field, value, placeholder) {
        return $('<input>', {
            type: 'text',
            class: 'form-control',
            'data-field': field,
            placeholder: placeholder || '',
            value: value || '',
        });
    }

    function resourceRow(resource, index) {
        resource = resource || {};

        var $row = $('<div class="zibll-ffqttg-resource-row">');
        var $head = $('<div class="flex ac jsb mb10">');
        $head.append($('<strong>').text('资源 ' + (index + 1)));
        $head.append(
            $('<button>', {
                type: 'button',
                class: 'but c-red zibll-ffqttg-remove',
                title: '删除资源',
            }).append($('<i class="fa fa-trash-o" aria-hidden="true">'))
        );
        $row.append($head);

        $row.append(fieldLabel('下载链接'));
        $row.append(textInput('url', resource.url, '请输入 HTTP 或 HTTPS 下载链接'));

        $row.append(fieldLabel('提取码'));
        $row.append(textInput('extract_code', resource.extract_code, '没有提取码可留空'));

        $row.append(fieldLabel('解压码'));
        $row.append(textInput('unzip_code', resource.unzip_code, '没有解压码可留空'));

        $row.append(fieldLabel('可见权限'));
        var $select = $('<select class="form-control" data-field="visibility">');
        $.each(config.visibility || {}, function (value, label) {
            $select.append($('<option>', { value: value, text: label }));
        });
        $select.val(resource.visibility || 'logged');
        $row.append($select);

        return $row;
    }

    function refreshNames($panel) {
        $panel.find('.zibll-ffqttg-resource-row').each(function (index) {
            $(this)
                .find('[data-field]')
                .each(function () {
                    var field = $(this).attr('data-field');
                    $(this).attr('name', resourcePrefix + '[' + index + '][' + field + ']');
                });
        });
        $panel.find('.zibll-ffqttg-resource-row strong').each(function (index) {
            $(this).text('资源 ' + (index + 1));
        });
    }

    function makePanel() {
        var $panel = $('<div class="zib-widget mb10-sm zibll-ffqttg-resource-panel">');
        var $body = $('<div class="zibll-ffqttg-resource-panel-body">');
        var resources = Array.isArray(config.resources) ? config.resources : [];

        $panel.append($('<div class="title-theme mb10">').text('资源下载设置'));
        $body.append(
            $('<input>', {
                type: 'hidden',
                name: 'zibll_ffqttg_nonce',
                value: config.nonce || '',
            })
        );
        $body.append($('<p class="muted-3-color em09 mb10">').text('填写链接后，资源会显示在文章正文上方。'));
        $body.append($('<div class="zibll-ffqttg-resource-list">'));
        $body.append(
            $('<button>', {
                type: 'button',
                class: 'but c-blue zibll-ffqttg-add',
            }).append($('<i class="fa fa-plus mr6" aria-hidden="true">')).append('添加资源')
        );
        $panel.append($body);

        var $list = $panel.find('.zibll-ffqttg-resource-list');
        if (resources.length) {
            $.each(resources, function (index, resource) {
                $list.append(resourceRow(resource, index));
            });
        } else {
            $list.append(resourceRow({}, 0));
        }

        refreshNames($panel);
        return $panel;
    }

    function findPayBox($form) {
        return $form.find('.sidebar [name="zibpay_s"]').first().closest('.zib-widget');
    }

    function findFallbackBox($form) {
        var $box = $form.find('.sidebar [name="category[]"]').first().closest('.zib-widget');
        if ($box.length) {
            return $box;
        }
        return $form.find('.sidebar .zib-widget').last();
    }

    function initPayBox($form) {
        var $payBox = findPayBox($form);
        if (!$payBox.length || $payBox.data('zibll-ffqttg-ready')) {
            return $payBox;
        }

        var $title = $payBox.children('.title-theme').first();
        if (!$title.length) {
            return $payBox;
        }

        $payBox.data('zibll-ffqttg-ready', true).addClass('zibll-ffqttg-paybox is-collapsed');
        $title
            .attr({
                role: 'button',
                tabindex: 0,
                'aria-expanded': 'false',
            })
            .append($('<i class="fa fa-angle-down zibll-ffqttg-paybox-arrow ml6" aria-hidden="true">'));

        function toggle() {
            var open = !$payBox.hasClass('is-collapsed');
            $payBox.toggleClass('is-collapsed', open);
            $title.attr('aria-expanded', open ? 'false' : 'true');
        }

        $title.on('click', toggle);
        $title.on('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });

        return $payBox;
    }

    function init() {
        var $form = $('.newposts-wrap').closest('form');
        if (!$form.length || $form.find('.zibll-ffqttg-resource-panel').length) {
            return;
        }

        var $payBox = initPayBox($form);
        var $panel = makePanel();
        var $anchor = $payBox.length ? $payBox : findFallbackBox($form);

        if ($anchor.length) {
            $anchor.after($panel);
        } else {
            $form.find('.sidebar').prepend($panel);
        }

        $panel.on('click', '.zibll-ffqttg-add', function () {
            var $list = $panel.find('.zibll-ffqttg-resource-list');
            if ($list.find('.zibll-ffqttg-resource-row').length >= (parseInt(config.max, 10) || 20)) {
                if (typeof window.notyf === 'function') {
                    window.notyf('最多只能添加 20 个资源。', 'warning');
                }
                return;
            }
            $list.append(resourceRow({}, $list.children().length));
            refreshNames($panel);
        });

        $panel.on('click', '.zibll-ffqttg-remove', function () {
            var $list = $panel.find('.zibll-ffqttg-resource-list');
            $(this).closest('.zibll-ffqttg-resource-row').remove();
            if (!$list.children().length) {
                $list.append(resourceRow({}, 0));
            }
            refreshNames($panel);
        });
    }

    $(init);
})(jQuery, window);
