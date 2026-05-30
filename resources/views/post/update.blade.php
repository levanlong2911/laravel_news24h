@extends('layouts.base', ['title' => __('post.update_post')])
@section('title', __('post.update_post'))
@section('css')
    <link rel="stylesheet" href="{{ asset('assets/css/tag.css') }}">
@endsection
@section('script')
    <script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
    <script src="/assets/plugins/jquery-validation/additional-methods.min.js"></script>
    <script src="/assets/plugins/jquery-validation/localization/messages_ja.min.js"></script>
    <script>
        $.validator.setDefaults({
            ignore: []
        });
        $('#quickForm').on('submit', function() {
            Object.keys(CKEDITOR.instances).forEach(function(instance) {
                CKEDITOR.instances[instance].updateElement();
            });
        });
        $(document).ready (function() {
            $('#quickForm').validate({
                rules: {
                    title: {
                        required: true,
                        maxlength: 500,
                        minlength: 5,
                    },
                    slug: {
                        required: true,
                    },
                    editor_content: {
                        required: true,
                        minlength: 200,
                    },
                    category: {
                        required: true,
                    },
                    tag_names: {
                        required: true,
                    },
                    image: {
                        required: true,
                        url: true,
                    },
                },
                messages: {
                    title: {
                        required: "{{ __('post.validate_title_required') }}",
                        maxlength: "{{ __('post.validate_max_required') }}",
                        minlength: "{{ __('post.validate_min_title_required') }}",
                    },
                    slug: {
                        required: "{{ __('post.validate_title_required') }}",
                    },
                    editor_content: {
                        required: "{{ __('post.validate_editor_content_required') }}",
                        minlength: "{{ __('post.validate_editor_content_required') }}",
                    },
                    category: {
                        required: "{{ __('post.validate_category_required') }}",
                    },
                    tag_names: {
                        required: "{{ __('post.validate_tag_required') }}",
                    },
                    image: {
                        required: "{{ __('post.validate_image_required') }}",
                        url: "{{ __('post.validate_url_required') }}",
                    },
                },
                errorElement: 'span',
                errorPlacement: function(error, element) {
                    if (element.closest('.inputMessage').length) {
                        error.addClass('invalid-feedback');
                        element.closest('.inputMessage').append(error);
                    } else {
                        error.insertAfter(element);
                    }
                },
                highlight: function(element, errorClass, validClass) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element, errorClass, validClass) {
                    $(element).removeClass('is-invalid');
                }
            });
        })
    </script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/ckeditor.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.5.11/adapters/jquery.js"></script>
    <script>
        var route_prefix = "/filemanager";
    </script>
    <script>
        {!! \File::get(base_path('vendor/unisharp/laravel-filemanager/public/js/stand-alone-button.js')) !!}
    </script>
    <script>
        $('#editor_content').filemanager('image', {
            prefix: route_prefix
        });
        // $('#lfm').filemanager('file', {prefix: route_prefix});
    </script>
    <script>
        $('textarea[name=editor_content]').ckeditor({
            height: 100,
            filebrowserImageBrowseUrl: route_prefix + '?type=Images',
            filebrowserImageUploadUrl: route_prefix + '/upload?type=Images&_token=',
            filebrowserBrowseUrl: route_prefix + '?type=Files',
            filebrowserUploadUrl: route_prefix + '/upload?type=Files&_token='
        });
    </script>
    <script>
        $('#lfm').filemanager('image', {
            prefix: route_prefix
        });
        // $('#lfm').filemanager('file', {prefix: route_prefix});
    </script>

    <script>
        var lfm = function(id, type, options) {
            let button = document.getElementById(id);

            button.addEventListener('click', function() {
                var route_prefix = (options && options.prefix) ? options.prefix : '/filemanager';
                var target_input = document.getElementById(button.getAttribute('data-input'));
                var target_preview = document.getElementById(button.getAttribute('data-preview'));

                window.open(route_prefix + '?type=' + options.type || 'file', 'FileManager',
                    'width=900,height=600');
                window.SetUrl = function(items) {
                    var file_path = items.map(function(item) {
                        return item.url;
                    }).join(',');

                    // set the value of the desired input to image url
                    target_input.value = file_path;
                    target_input.dispatchEvent(new Event('change'));

                    // clear previous preview
                    target_preview.innerHtml = '';

                    // set or change the preview image src
                    items.forEach(function(item) {
                        let img = document.createElement('img')
                        img.setAttribute('style', 'height: 5rem')
                        img.setAttribute('src', item.thumb_url)
                        target_preview.appendChild(img);
                    });

                    // trigger change event
                    target_preview.dispatchEvent(new Event('change'));
                };
            });
        };

        lfm('lfm2', 'file', {
            prefix: route_prefix
        });

        lfm('lfm-fb2', 'image', {
            prefix: route_prefix,
            type: 'Images'
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {

            const editorContentId = 'editor_content';
            const titleInput = document.getElementById("title");

            /* =============================
            *  INIT CKEDITOR
            * ============================= */
            if (!CKEDITOR.instances[editorContentId]) {
                CKEDITOR.replace(editorContentId, {
                    entities: false,
                    entities_latin: false,
                    autoParagraph: false
                });
            }

            function getEditorContent() {
                return CKEDITOR.instances[editorContentId].getData();
            }

            function setEditorContent(content) {
                const editor = CKEDITOR.instances[editorContentId];
                if (!editor) return;

                editor.setData(content, function () {
                    editor.updateElement();
                });
            }

            /* =============================
            *  REPLACE MAP
            * ============================= */
            const MAP_TO_FAKE = {
                "h": "Һ",
                "k": "ƙ"
            };

            const MAP_TO_REAL = {
                "Һ": "h",
                "ƙ": "k"
            };

            /* =============================
            *  SAFE TEXT NODE REPLACE
            * ============================= */
            function replaceMultipleInNode(node, replaceMap) {
                if (node.nodeType === 3) {
                    let text = node.nodeValue;

                    for (const [find, replace] of Object.entries(replaceMap)) {
                        text = text.replace(new RegExp(find, "g"), replace);
                    }

                    node.nodeValue = text;
                }
                else if (node.nodeType === 1) {

                    // Skip các thẻ không nên đụng
                    const skipTags = ['SCRIPT', 'STYLE', 'CODE', 'PRE'];
                    if (skipTags.includes(node.tagName)) return;

                    node.childNodes.forEach(child => replaceMultipleInNode(child, replaceMap));
                }
            }

            /* =============================
            *  MAIN REPLACE FUNCTION
            * ============================= */
            function replaceMultipleText(replaceMap) {

                /* Replace title */
                if (titleInput) {
                    let value = titleInput.value;
                    for (const [find, replace] of Object.entries(replaceMap)) {
                        value = value.replace(new RegExp(find, "g"), replace);
                    }
                    titleInput.value = value;
                }

                /* Replace CKEditor content */
                let content = getEditorContent();
                let tempDiv = document.createElement("div");
                tempDiv.innerHTML = content;

                replaceMultipleInNode(tempDiv, replaceMap);

                setEditorContent(tempDiv.innerHTML);
            }

            /* =============================
            *  BUTTON EVENTS
            * ============================= */
            document.getElementById("replaceToGreekI").addEventListener("click", function () {
                replaceMultipleText(MAP_TO_FAKE);
            });

            document.getElementById("replaceToLatinI").addEventListener("click", function () {
                replaceMultipleText(MAP_TO_REAL);
            });

        });
    </script>
    <script>
        $(document).ready(function () {
            function loadTags(categoryId) {
                if (categoryId) {
                    $.ajax({
                        url: '{{ url("admin/get-tags") }}',
                        type: 'GET',
                        data: { category_id: categoryId },
                        success: function (data) {
                            if (data.length > 0) {
                                let tagNames = data.map(tag => tag.name).join(', '); // Chuỗi tag name
                                let tagIds = data.map(tag => tag.id).join(', '); // Chuỗi tag id

                                $('#tag').val(tagNames); // Hiển thị tên tag trong input
                                $('#tag-hidden').val(tagIds); // Lưu ID vào input hidden
                            } else {
                                $('#tag').val('');
                                $('#tag-hidden').val('');
                            }
                        },
                        error: function () {
                            alert('Không thể lấy danh sách tags!');
                        }
                    });
                } else {
                    $('#tag').val('');
                    $('#tag-hidden').val('');
                }
            }

            // Khi trang load lại, nếu có dữ liệu cũ => tự động load tags
            $('#category').change(function () {
                loadTags($(this).val());
            }).trigger('change');

            // Khi chọn category mới, load lại tags
            $('#category').change(function () {
                loadTags($(this).val());
            });
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {

            // Lấy username đang đăng nhập từ Laravel
            let currentUser = "{{ Auth::user()->name ?? 'user' }}";

            // Hàm tạo slug
            function generateSlug(title) {
                let slug = title
                    .toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "") // Bỏ dấu tiếng Việt
                    .replace(/[^a-z0-9]+/g, "-")
                    .replace(/^-+|-+$/g, "");

                let userSlug = currentUser
                    .toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                    .replace(/[^a-z0-9]+/g, "-")
                    .replace(/^-+|-+$/g, "");

                return slug + "-" + userSlug;
            }

            // Khi user chỉnh title → auto cập nhật slug
            document.getElementById("title").addEventListener("input", function () {
                let title = this.value;
                let newSlug = generateSlug(title);
                document.getElementById("slug").value = newSlug;
            });

            // Khi lấy link API (success) → auto cập nhật slug theo title
            $(document).ajaxSuccess(function (event, xhr, settings) {
                if (settings.url.includes("get-link")) { // đúng API lấy link
                    let response = xhr.responseJSON;
                    if (response && response.title) {
                        let autoSlug = generateSlug(response.title);
                        $("#slug").val(autoSlug);
                    }
                }
            });

        });
    </script>
    <script>
    // ── FB Image Canvas Preview ────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const canvas      = document.getElementById('fb-canvas');
        if (!canvas) return;
        const ctx         = canvas.getContext('2d');
        const placeholder = document.getElementById('fb-canvas-placeholder');
        const downloadBtn = document.getElementById('btn-download-fb');
        const thumb2El    = document.getElementById('fb-thumbnail2');
        const W = 1638, H = 2048;
        canvas.width = W; canvas.height = H;
        let currentTpl  = 1;
        let panelColor    = '#000000';
        let panelOpacity  = 0.93;
        let imgBrightness = 0;
        let _genId = 0, _genTimer = null;
        let circleX = Math.round(W * 0.72), circleY = Math.round(H * 0.34);
        let _lastImg1 = null, _lastUrl1 = '', _lastImg1Cors = true;
        let _lastImg2 = null, _lastUrl2 = '', _lastFbText = '';
        let _img2NoBg = null, _img2Bounds = null;
        let _dragging = false, _dragOffX = 0, _dragOffY = 0;
        let img2Scale = 1.0;

        // ── Color helpers ─────────────────────────────────────────────────────
        function parseHex(hex) {
            return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)];
        }
        function hexToRgba(hex, a) {
            const [r,g,b] = parseHex(hex);
            return `rgba(${r},${g},${b},${a})`;
        }
        function panelRgba(alphaOverride) {
            return hexToRgba(panelColor, alphaOverride !== undefined ? alphaOverride : panelOpacity);
        }
        function autoText(hex) {
            const [r,g,b] = parseHex(hex);
            return ((0.299*r+0.587*g+0.114*b)/255) > 0.55 ? '#111111' : '#FFFFFF';
        }

        // ── Helpers ──────────────────────────────────────────────────────────
        function coverImage(img) {
            const s = Math.max(W / img.naturalWidth, H / img.naturalHeight);
            ctx.drawImage(img, (W - img.naturalWidth*s)/2, (H - img.naturalHeight*s)/2,
                          img.naturalWidth*s, img.naturalHeight*s);
        }

        // Draw img2 person overlay ON TOP of everything (called after template elements)
        function drawPersonOverlay(img2) {
            if (!img2) return;
            const src = _img2NoBg || img2;
            const maxH = Math.round(H * 0.75);
            const maxW = Math.round(W * 0.58);
            const s  = Math.min(maxW / src.naturalWidth, maxH / src.naturalHeight) * img2Scale;
            const dw = Math.round(src.naturalWidth  * s);
            const dh = Math.round(src.naturalHeight * s);
            _img2Bounds = { x: circleX - dw/2, y: circleY - dh/2, w: dw, h: dh };
            // Drop shadow so person stands out even without bg removal
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,0.7)';
            ctx.shadowBlur  = 40;
            ctx.shadowOffsetX = 6;
            ctx.shadowOffsetY = 6;
            ctx.drawImage(src, _img2Bounds.x, _img2Bounds.y, dw, dh);
            ctx.restore();
        }

        // Draw only the background layer (img1 + brightness overlay)
        function getImageLayer(img1) {
            coverImage(img1);
            if (imgBrightness !== 0) {
                const alpha = Math.abs(imgBrightness) / 100;
                ctx.fillStyle = imgBrightness < 0
                    ? `rgba(0,0,0,${alpha})`
                    : `rgba(255,255,255,${alpha})`;
                ctx.fillRect(0, 0, W, H);
            }
        }

        function roundedRect(x, y, w, h, r) {
            ctx.beginPath();
            ctx.moveTo(x+r,y); ctx.lineTo(x+w-r,y);
            ctx.quadraticCurveTo(x+w,y,x+w,y+r); ctx.lineTo(x+w,y+h-r);
            ctx.quadraticCurveTo(x+w,y+h,x+w-r,y+h); ctx.lineTo(x+r,y+h);
            ctx.quadraticCurveTo(x,y+h,x,y+h-r); ctx.lineTo(x,y+r);
            ctx.quadraticCurveTo(x,y,x+r,y); ctx.closePath();
        }

        function setFont(fs) { ctx.font = `bold ${fs}px 'Arial Black', Arial, sans-serif`; }

        function wrapLines(text, maxW, fs) {
            setFont(fs);
            const words = text.split(' '); const lines = []; let line = '';
            for (const w of words) {
                const t = line ? line + ' ' + w : w;
                if (ctx.measureText(t).width > maxW && line) { lines.push(line); line = w; }
                else line = t;
            }
            if (line) lines.push(line);
            return lines;
        }

        // Shared word-wrap core for two-color (prefix + body) layout
        function wrapTwoColorLines(prefix, body, maxW, fs) {
            setFont(fs);
            const pw = ctx.measureText(prefix).width;
            const words = body.split(' ');
            const lines = []; let line = '', first = true;
            for (const w of words) {
                const t = line ? line + ' ' + w : w;
                const lim = first ? maxW - pw : maxW;
                if (ctx.measureText(t).width > lim && line) {
                    lines.push({ t: line, first }); line = w; first = false;
                } else line = t;
            }
            if (line) lines.push({ t: line, first });
            return lines;
        }

        function drawTwoColor(prefix, pColor, body, bColor, x, startY, maxW, fs, lh) {
            const lines = wrapTwoColorLines(prefix, body, maxW, fs);
            const pw = ctx.measureText(prefix).width;
            let ty = startY;
            for (const row of lines) {
                let lx = x;
                if (row.first) { ctx.fillStyle = pColor; ctx.fillText(prefix, lx, ty); lx += pw; }
                ctx.fillStyle = bColor; ctx.fillText(row.t, lx, ty); ty += lh;
            }
            return lines.length;
        }

        function countTwoColor(prefix, body, maxW, fs) {
            return wrapTwoColorLines(prefix, body, maxW, fs).length;
        }

        // Draw pill badge, return badge width
        function badge(label, x, y, bg, fg, fs) {
            ctx.font = `bold ${fs}px Arial, sans-serif`;
            const tw = ctx.measureText(label).width;
            const bw = tw + 32, bh = fs + 20;
            ctx.fillStyle = bg; roundedRect(x, y, bw, bh, 5); ctx.fill();
            ctx.fillStyle = fg; ctx.fillText(label, x + 16, y + bh - 8);
            return bw;
        }

        // Draw a block of text lines; returns final ty
        function renderLines(lines, x, startY, color, lh, align) {
            if (align) ctx.textAlign = align;
            ctx.fillStyle = color;
            let ty = startY;
            for (const l of lines) { ctx.fillText(l, x, ty); ty += lh; }
            if (align && align !== 'left') ctx.textAlign = 'left';
            return ty;
        }

        // ── Templates ─────────────────────────────────────────────────────────
        const PAD = 80, FS = 74, LH = 102;

        // T1: ESPN DIRECT — gradient + BREAKING badge + sharp white type
        function tpl1(img, text, img2) {
            getImageLayer(img);
            const badgeH = 36 + 20;
            const lines  = wrapLines(text, W - PAD*2, FS);
            const bH = 46 + badgeH + 28 + lines.length * LH + 60;
            const bY = H - bH;
            const g = ctx.createLinearGradient(0, bY - Math.round(bH * 0.6), 0, H);
            g.addColorStop(0, 'rgba(0,0,0,0)');
            g.addColorStop(0.35, 'rgba(0,0,0,0.88)');
            g.addColorStop(1,   'rgba(0,0,0,0.98)');
            ctx.fillStyle = g; ctx.fillRect(0, bY - Math.round(bH * 0.6), W, H - (bY - Math.round(bH * 0.6)));
            ctx.fillStyle = '#E8001D'; ctx.fillRect(0, bY, W, 7);
            badge('BREAKING', PAD, bY + 46, '#E8001D', '#FFFFFF', 36);
            setFont(FS);
            renderLines(lines, PAD, bY + 46 + badgeH + 28 + FS, '#FFFFFF', LH);
            ctx.fillStyle = 'rgba(255,255,255,0.22)';
            ctx.fillRect(PAD, H - 42, W - PAD*2, 2);
            drawPersonOverlay(img2);
        }

        // T2: SKY SPORTS LIVE — dark panel, left red bar, LIVE badge, broadcast feel
        function tpl2(img, text, img2) {
            getImageLayer(img);
            const tx       = PAD + 12 + 24;
            const lines    = wrapLines(text, W - tx - PAD, FS);
            const bH = 40 + 50 + 34 + 22 + lines.length * LH + 60;
            const bY = H - bH;
            const txtColor = autoText(panelColor);
            const dimColor = txtColor === '#FFFFFF' ? 'rgba(255,255,255,0.42)' : 'rgba(0,0,0,0.42)';
            ctx.fillStyle = panelRgba(); ctx.fillRect(0, bY, W, bH);
            ctx.fillStyle = 'rgba(255,255,255,0.10)'; ctx.fillRect(0, bY, W, 2);
            ctx.fillStyle = '#E8001D'; ctx.fillRect(PAD, bY + 38, 12, bH - 76);
            badge('LIVE', tx, bY + 40, '#E8001D', '#FFFFFF', 30);
            ctx.font = '500 28px Arial, sans-serif';
            ctx.fillStyle = dimColor;
            ctx.fillText('SPORTS · NEWS · 24H', tx, bY + 40 + 50 + 34);
            setFont(FS);
            renderLines(lines, tx, bY + 40 + 50 + 34 + 22 + FS, txtColor, LH);
            drawPersonOverlay(img2);
        }

        // T3: CHAMPION GOLD — dynamic dark panel, gold accent, prestige sport feel
        function tpl3(img, text, img2) {
            getImageLayer(img);
            const gold     = '#F2C94C';
            const lines    = wrapLines(text, W - PAD*2, FS);
            const pH = 80 + lines.length * LH + 60;
            const pY = H - pH;
            const txtColor = autoText(panelColor);
            ctx.fillStyle = panelRgba(); ctx.fillRect(0, pY, W, pH);
            ctx.fillStyle = gold; ctx.fillRect(0, pY, W, 8);
            const diamond = (x, y, s) => {
                ctx.save(); ctx.fillStyle = gold;
                ctx.translate(x, y); ctx.rotate(Math.PI/4);
                ctx.fillRect(-s/2, -s/2, s, s); ctx.restore();
            };
            diamond(PAD + 16, pY + 50, 18);
            diamond(W - PAD - 16, pY + 50, 18);
            ctx.font = 'bold 32px Arial, sans-serif';
            ctx.fillStyle = gold; ctx.textAlign = 'center';
            ctx.fillText('SPORT FLASH', W/2, pY + 58);
            ctx.textAlign = 'left';
            ctx.fillStyle = 'rgba(242,201,76,0.28)'; ctx.fillRect(PAD, pY + 72, W - PAD*2, 1);
            setFont(FS);
            renderLines(lines, W/2, pY + 80 + FS, txtColor, LH, 'center');
            drawPersonOverlay(img2);
        }

        // T4: IMPACT — full vignette, centered bold text, red underline, max drama
        function tpl4(img, text, img2) {
            getImageLayer(img);
            const vg = ctx.createRadialGradient(W/2, H/2, H*0.16, W/2, H/2, H*0.76);
            vg.addColorStop(0, 'rgba(0,0,0,0)'); vg.addColorStop(1, 'rgba(0,0,0,0.84)');
            ctx.fillStyle = vg; ctx.fillRect(0, 0, W, H);
            const bY4 = Math.round(H * 0.50);
            const bg = ctx.createLinearGradient(0, bY4, 0, H);
            bg.addColorStop(0, 'rgba(0,0,0,0)'); bg.addColorStop(1, 'rgba(0,0,0,0.92)');
            ctx.fillStyle = bg; ctx.fillRect(0, bY4, W, H - bY4);
            const FS4 = 82, LH4 = 112;
            const lines = wrapLines(text, W - PAD*2, FS4);
            const zoneY = Math.round(H * 0.50), zoneH = H - 100 - zoneY;
            const totalTextH = lines.length * LH4;
            let ty = zoneY + Math.max(0, Math.floor((zoneH - totalTextH) / 2)) + FS4;
            setFont(FS4); ctx.textAlign = 'center';
            for (const l of lines) {
                ctx.fillStyle = 'rgba(0,0,0,0.55)'; ctx.fillText(l, W/2 + 3, ty + 3);
                ctx.fillStyle = '#FFFFFF';           ctx.fillText(l, W/2,     ty);
                ty += LH4;
            }
            ctx.textAlign = 'left';
            const underY = ty - LH4 + 20;
            ctx.fillStyle = '#E8001D'; ctx.fillRect(W/2 - 140, underY, 280, 7);
            ctx.font = 'bold 30px Arial, sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.65)'; ctx.textAlign = 'center';
            ctx.fillText('— BREAKING NEWS —', W/2, underY + 52); ctx.textAlign = 'left';
            drawPersonOverlay(img2);
        }

        // T5: EDITORIAL — dynamic panel, magazine sport style, color-adaptive text
        function tpl5(img, text, img2) {
            getImageLayer(img);
            const prefix      = '›  ';
            const n5          = countTwoColor(prefix, text, W - PAD*2, FS);
            const pH          = 80 + n5 * LH + 60;
            const pY          = H - pH;
            const txtColor    = autoText(panelColor);
            const accentColor = txtColor === '#FFFFFF' ? '#FF4444' : '#E8001D';
            const dimColor    = txtColor === '#FFFFFF' ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.12)';
            ctx.fillStyle = panelRgba(); ctx.fillRect(0, pY, W, pH);
            ctx.fillStyle = accentColor; ctx.fillRect(0, pY, W, 8);
            ctx.font = 'bold 30px Arial, sans-serif';
            ctx.fillStyle = accentColor; ctx.fillText('BREAKING NEWS', PAD, pY + 52);
            ctx.fillStyle = dimColor; ctx.fillRect(PAD, pY + 64, W - PAD*2, 2);
            drawTwoColor(prefix, accentColor, text, txtColor, PAD, pY + 80 + FS, W - PAD*2, FS, LH);
            drawPersonOverlay(img2);
        }

        // ── Draw scene ────────────────────────────────────────────────────────
        const tplFns = { 1: tpl1, 2: tpl2, 3: tpl3, 4: tpl4, 5: tpl5 };

        function drawScene(img, fbText, canExport, img2) {
            _lastImg1 = img; _lastImg2 = img2 || null; _lastFbText = fbText;
            ctx.clearRect(0, 0, W, H);
            const fn = tplFns[currentTpl];
            if (fn) fn(img, fbText, img2 || null);
            if (!canExport) return;
            const thisId = ++_genId;
            try {
                canvas.toBlob(function (blob) {
                    if (thisId !== _genId) return;
                    if (!blob) return;
                    const old = downloadBtn.getAttribute('href');
                    if (old && old.startsWith('blob:')) URL.revokeObjectURL(old);
                    downloadBtn.href = URL.createObjectURL(blob);
                    downloadBtn.style.opacity = '1';
                    downloadBtn.style.pointerEvents = '';
                }, 'image/jpeg', 0.95);
            } catch (e) {
                console.warn('[FB Preview] Canvas tainted — download unavailable');
            }
        }

        function generate() {
            const url    = (document.getElementById('thumbnail').value  || '').trim();
            const url2   = thumb2El ? (thumb2El.value || '').trim() : '';
            const fbText = (document.getElementById('image_text').value || '').trim();
            if (!url) {
                ctx.clearRect(0, 0, W, H);
                ctx.fillStyle = '#e9ecef'; ctx.fillRect(0, 0, W, H);
                if (placeholder) placeholder.style.display = 'block';
                downloadBtn.style.opacity = '0.5';
                downloadBtn.style.pointerEvents = 'none';
                return;
            }
            if (placeholder) placeholder.style.display = 'none';
            if (url2 !== _lastUrl2) { _img2NoBg = null; _lastUrl2 = url2; }

            let canExport = true, img1Loaded = null, img2Loaded = null;
            let pending = url2 ? 2 : 1;

            function onReady() {
                pending--;
                if (pending > 0) return;
                if (!img1Loaded) return;
                drawScene(img1Loaded, fbText, canExport, img2Loaded);
            }

            function loadImg(src, onLoad, onGiveUp) {
                const a = new Image();
                a.crossOrigin = 'anonymous';
                a.onload  = () => onLoad(a, true);
                a.onerror = () => {
                    const b = new Image();
                    b.onload  = () => onLoad(b, false);
                    b.onerror = onGiveUp;
                    b.src = src;
                };
                a.src = src;
            }

            loadImg(url,
                (img, cors) => { img1Loaded = img; if (!cors) canExport = false; onReady(); },
                ()          => { console.warn('[FB Preview] Cannot load image:', url); onReady(); });

            if (url2) {
                loadImg(url2,
                    (img, cors) => { img2Loaded = img; if (!cors) canExport = false; onReady(); },
                    ()          => onReady());
            }
        }

        document.getElementById('thumbnail').addEventListener('change', generate);
        document.getElementById('thumbnail').addEventListener('input', generate);
        document.getElementById('image_text').addEventListener('input', function () {
            clearTimeout(_genTimer); _genTimer = setTimeout(generate, 300);
        });
        document.getElementById('btn-refresh-fb').addEventListener('click', generate);
        document.querySelectorAll('.fb-tpl-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                currentTpl = parseInt(this.dataset.tpl);
                document.querySelectorAll('.fb-tpl-btn').forEach(function(b) {
                    b.classList.replace('btn-dark', 'btn-outline-dark');
                });
                this.classList.replace('btn-outline-dark', 'btn-dark');
                generate();
            });
        });

        const brightnessEl   = document.getElementById('fb-img-brightness');
        const brightnessLabel = document.getElementById('fb-brightness-label');
        const panelColorEl   = document.getElementById('fb-panel-color');
        const panelOpacityEl = document.getElementById('fb-panel-opacity');
        const opacityLabel   = document.getElementById('fb-opacity-label');
        const clearFb2Btn    = document.getElementById('btn-clear-fb2');

        if (brightnessEl) brightnessEl.addEventListener('input', function () {
            imgBrightness = parseInt(this.value);
            if (brightnessLabel) brightnessLabel.textContent = this.value > 0 ? '+' + this.value : this.value;
            generate();
        });

        if (panelColorEl) panelColorEl.addEventListener('input', function () {
            panelColor = this.value; generate();
        });
        if (panelOpacityEl) panelOpacityEl.addEventListener('input', function () {
            panelOpacity = parseInt(this.value) / 100;
            if (opacityLabel) opacityLabel.textContent = this.value + '%';
            generate();
        });
        if (thumb2El) { thumb2El.addEventListener('change', generate); thumb2El.addEventListener('input', generate); }
        if (clearFb2Btn) clearFb2Btn.addEventListener('click', function () {
            if (thumb2El) thumb2El.value = '';
            const h = document.getElementById('fb-holder2');
            if (h) h.innerHTML = '';
            generate();
        });

        const img2ScaleEl    = document.getElementById('fb-img2-scale');
        const img2ScaleLabel = document.getElementById('fb-img2-scale-label');
        if (img2ScaleEl) img2ScaleEl.addEventListener('input', function () {
            img2Scale = parseInt(this.value) / 100;
            if (img2ScaleLabel) img2ScaleLabel.textContent = this.value + '%';
            if (_lastImg1 && _lastImg2) drawScene(_lastImg1, _lastFbText, false, _lastImg2);
        });

        if (document.getElementById('thumbnail').value.trim()) {
            generate();
        }

        // ── Drag img2 overlay ────────────────────────────────────────────────
        function toCanvas(e) {
            const rect = canvas.getBoundingClientRect();
            const sx = W / rect.width, sy = H / rect.height;
            const src = e.touches ? e.touches[0] : e;
            return { x: (src.clientX - rect.left) * sx, y: (src.clientY - rect.top) * sy };
        }

        function inOverlay(p) {
            if (!_img2Bounds) return false;
            return p.x >= _img2Bounds.x && p.x <= _img2Bounds.x + _img2Bounds.w &&
                   p.y >= _img2Bounds.y && p.y <= _img2Bounds.y + _img2Bounds.h;
        }

        function redrawDrag(finish) {
            if (!_lastImg1 || !_lastImg2) return;
            drawScene(_lastImg1, _lastFbText, finish, _lastImg2);
        }

        canvas.addEventListener('mousedown', function (e) {
            if (!_lastImg2) return;
            const p = toCanvas(e);
            if (!inOverlay(p)) return;
            _dragging = true; _dragOffX = p.x - circleX; _dragOffY = p.y - circleY;
            canvas.style.cursor = 'grabbing'; e.preventDefault();
        });

        canvas.addEventListener('mousemove', function (e) {
            if (!_lastImg2) return;
            const p = toCanvas(e);
            if (_dragging) {
                circleX = Math.round(p.x - _dragOffX);
                circleY = Math.round(p.y - _dragOffY);
                redrawDrag(false);
            } else {
                canvas.style.cursor = inOverlay(p) ? 'grab' : 'default';
            }
        });

        canvas.addEventListener('mouseup', function () {
            if (!_dragging) return;
            _dragging = false; canvas.style.cursor = 'grab'; redrawDrag(true);
        });

        canvas.addEventListener('mouseleave', function () {
            if (_dragging) { _dragging = false; redrawDrag(true); }
            canvas.style.cursor = 'default';
        });

        canvas.addEventListener('touchstart', function (e) {
            if (!_lastImg2) return;
            const p = toCanvas(e);
            if (!inOverlay(p)) return;
            _dragging = true; _dragOffX = p.x - circleX; _dragOffY = p.y - circleY;
            e.preventDefault();
        }, { passive: false });

        canvas.addEventListener('touchmove', function (e) {
            if (!_dragging) return;
            const p = toCanvas(e);
            circleX = Math.round(p.x - _dragOffX);
            circleY = Math.round(p.y - _dragOffY);
            redrawDrag(false); e.preventDefault();
        }, { passive: false });

        canvas.addEventListener('touchend', function () {
            if (!_dragging) return;
            _dragging = false; redrawDrag(true);
        });

        // ── Background removal ────────────────────────────────────────────────
        async function removeBgImg2() {
            if (!_lastImg2) return;
            const btn = document.getElementById('btn-remove-bg2');
            const setBtn = (txt, disabled) => { if (btn) { btn.textContent = txt; btn.disabled = disabled; } };
            setBtn('⏳ Đang xử lý...', true);
            try {
                // Step 1: get blob via temp canvas (works if CORS succeeded)
                const blob = await new Promise((resolve, reject) => {
                    const tmp = document.createElement('canvas');
                    tmp.width  = _lastImg2.naturalWidth;
                    tmp.height = _lastImg2.naturalHeight;
                    try {
                        tmp.getContext('2d').drawImage(_lastImg2, 0, 0);
                        tmp.toBlob(b => b ? resolve(b) : reject(new Error('empty')));
                    } catch (e) { reject(e); }
                });
                // Step 2: load AI library + remove bg
                const { removeBackground } = await import('https://esm.sh/@imgly/background-removal');
                const resultBlob = await removeBackground(blob);
                // Step 3: load result as Image
                const img = new Image();
                img.onload = () => {
                    _img2NoBg = img;
                    if (_lastImg1) drawScene(_lastImg1, _lastFbText, true, _lastImg2);
                    setBtn('✓ Đã xóa nền', false);
                };
                img.src = URL.createObjectURL(resultBlob);
            } catch (e) {
                console.error('[RemoveBG]', e);
                setBtn('⚠ CORS block', false);
                alert('Ảnh bị CORS block — không thể lấy pixel data.\nThử download ảnh rồi upload lên server của bạn.');
            }
        }

        const removeBg2Btn = document.getElementById('btn-remove-bg2');
        if (removeBg2Btn) removeBg2Btn.addEventListener('click', removeBgImg2);
    });
    </script>

@endsection
@section('content')
    <section class="content">
        <form id="quickForm" action="{{ route('post.update', $listPost->id) }}" method="post">
            @csrf
            <div class="row">
                <div class="col-md-9">
                    <div class="card card-primary">
                        <div class="card-body">
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.title') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ $listPost->title }}"
                                            class="form-control{{ $errors->has('title') ? ' is-invalid' : '' }} col-12"
                                            name="title" id="title" placeholder="">
                                        @error('title')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.slug') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ $listPost->slug }}"
                                            class="form-control{{ $errors->has('slug') ? ' is-invalid' : '' }} col-12"
                                            name="slug" id="slug" placeholder="" readonly>
                                        @error('slug')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.image_text') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <input type="text" value="{{ $listPost->fb_image_text }}"
                                            class="form-control{{ $errors->has('image_text') ? ' is-invalid' : '' }} col-12"
                                            name="image_text" id="image_text" placeholder="">
                                        @error('image_text')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.post_content') }}</p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <textarea class="form-control col-12"
                                            name="post_content" id="post_content"
                                            rows="8"
                                            style="white-space: pre-wrap; resize: vertical;">{{ $listPost->fb_post_content }}</textarea>
                                        @error('post_content')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('admin.content') }}<span style="color: red; ">
                                            *</span></p>
                                </div>
                                {{-- <label for="">{{ __('admin.content') }}</label> --}}
                                <textarea id="editor_content" name="editor_content" class="form-control {{ $errors->has('editor_content') ? ' is-invalid' : '' }}" rows="4" style="height: 1000px;">
                                    {{ $listPost->content }}
                                </textarea>
                                @error('title')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
                <div class="col-md-3">
                    <div class="card card-secondary">
                        <div class="card-body">
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.category') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <select
                                            class="form-control {{ $errors->has('category') ? ' is-invalid' : '' }} col-12"
                                            name="category" id="category">
                                            <option value>Select category</option>
                                            @foreach ($listsCate as $item)
                                                <option value="{{ $item->id }}" {{ old('category') ? (old('category') == $item->id ? 'selected' : '') : ($listPost->category_id == $item->id ? 'selected' : '') }}>{{  $item->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('name')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.tag') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div id="tag-container">
                                        <input type="text" value="{{ old('tag_names') ?? old('tag_names') }}"
                                            class="form-control{{ $errors->has('tag') ? ' is-invalid' : '' }} col-12"
                                            name="tag_names" id="tag" readonly>
                                        {{-- @error('tag')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror --}}
                                        <input type="hidden" name="tagIds" id="tag-hidden" value="{{ old('tag') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.image') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <span class="input-group-btn">
                                            <a id="lfm" data-input="thumbnail" data-preview="holder"
                                                class="btn btn-primary text-white" style="margin-bottom:10px;">
                                                Choose image
                                            </a>
                                        </span>
                                        <input id="thumbnail" type="text" value="{{ $listPost->thumbnail }}"
                                            class="form-control{{ $errors->has('image') ? ' is-invalid' : '' }} col-12"
                                            name="image" placeholder="">
                                        @error('image')
                                            <span class="invalid-feedback d-block" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                    <div id="holder" style="margin-top:15px;max-height:100px;"></div>
                                </div>
                            </div>

                            {{-- FB Image Preview --}}
                            <div class="form-group">
                                <p class="align-middle p-0 mb-1 font-weight-bold" style="font-size:13px;">
                                    <i class="fab fa-facebook" style="color:#1877f2;"></i>
                                    Facebook Image Preview
                                </p>
                                {{-- Template selector --}}
                                <div class="mb-2" style="display:flex; gap:4px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-sm btn-dark fb-tpl-btn" data-tpl="1"
                                        style="font-size:11px; padding:2px 7px;"
                                        title="ESPN Direct: gradient + badge">ESPN</button>
                                    <button type="button" class="btn btn-sm btn-outline-dark fb-tpl-btn" data-tpl="2"
                                        style="font-size:11px; padding:2px 7px;"
                                        title="Sky Sports Live: dark panel + red bar">Live TV</button>
                                    <button type="button" class="btn btn-sm btn-outline-dark fb-tpl-btn" data-tpl="3"
                                        style="font-size:11px; padding:2px 7px;"
                                        title="Champion Gold: prestige gold accent">Gold</button>
                                    <button type="button" class="btn btn-sm btn-outline-dark fb-tpl-btn" data-tpl="4"
                                        style="font-size:11px; padding:2px 7px;"
                                        title="Impact: cinematic vignette + centered">Impact</button>
                                    <button type="button" class="btn btn-sm btn-outline-dark fb-tpl-btn" data-tpl="5"
                                        style="font-size:11px; padding:2px 7px;"
                                        title="Editorial: white panel + dark text">Editorial</button>
                                </div>
                                {{-- Canvas — responsive, fills sidebar width, ratio 1638:2048 --}}
                                <div style="position:relative; width:100%; padding-top:125.03%;
                                            border:1px solid #dee2e6; border-radius:6px; overflow:hidden;
                                            background:#e9ecef;">
                                    <canvas id="fb-canvas"
                                        style="position:absolute; top:0; left:0; width:100%; height:100%; display:block;">
                                    </canvas>
                                    <div id="fb-canvas-placeholder"
                                        style="position:absolute; top:50%; left:50%;
                                               transform:translate(-50%,-50%);
                                               color:#aaa; font-size:11px; text-align:center;
                                               pointer-events:none; width:80%;">
                                        Chọn ảnh &amp; nhập Image Text<br>để xem preview
                                    </div>
                                </div>
                                {{-- Controls --}}
                                <div style="margin-top:8px; display:flex; flex-direction:column; gap:6px;">
                                    {{-- Action buttons --}}
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" id="btn-refresh-fb"
                                            class="btn btn-sm btn-outline-primary" style="flex:1;">
                                            <i class="fas fa-sync-alt"></i> Refresh
                                        </button>
                                        <a id="btn-download-fb" class="btn btn-sm btn-success"
                                            download="fb_image.jpg"
                                            style="flex:1; text-align:center; opacity:0.5; pointer-events:none;">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                    {{-- Brightness --}}
                                    <div>
                                        <small class="font-weight-bold text-secondary">Brightness</small>
                                        <div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                                            <span style="font-size:12px;">🌑</span>
                                            <input type="range" id="fb-img-brightness" min="-100" max="100" value="0"
                                                style="flex:1;">
                                            <span style="font-size:12px;">☀</span>
                                            <span id="fb-brightness-label"
                                                style="font-size:11px; color:#666; min-width:26px; text-align:right;">0</span>
                                        </div>
                                    </div>
                                    {{-- Panel Color --}}
                                    <div>
                                        <small class="font-weight-bold text-secondary">Panel Color</small>
                                        <div style="display:flex; align-items:center; gap:5px; margin-top:2px;">
                                            <input type="color" id="fb-panel-color" value="#000000"
                                                style="width:30px; height:26px; padding:1px; border:1px solid #ced4da;
                                                       border-radius:4px; cursor:pointer; flex-shrink:0;">
                                            <input type="range" id="fb-panel-opacity" min="0" max="100" value="93"
                                                style="flex:1;">
                                            <span id="fb-opacity-label"
                                                style="font-size:11px; color:#666; min-width:30px; text-align:right;">93%</span>
                                        </div>
                                    </div>
                                    {{-- Image 2 VS split --}}
                                    <div>
                                        <small class="font-weight-bold text-secondary">
                                            Image 2 <span style="font-weight:400;">(person overlay)</span>
                                        </small>
                                        <div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                                            <a id="lfm-fb2" data-input="fb-thumbnail2" data-preview="fb-holder2"
                                                class="btn btn-sm btn-outline-secondary"
                                                style="font-size:11px; padding:2px 8px; white-space:nowrap;">
                                                + Choose
                                            </a>
                                            <button type="button" id="btn-clear-fb2"
                                                class="btn btn-sm btn-outline-danger"
                                                style="font-size:11px; padding:2px 6px;" title="Clear">✕</button>
                                            <button type="button" id="btn-remove-bg2"
                                                class="btn btn-sm btn-outline-primary"
                                                style="font-size:11px; padding:2px 6px; white-space:nowrap;" title="Remove background from Image 2">Remove BG</button>
                                        </div>
                                        <input id="fb-thumbnail2" type="text" class="form-control form-control-sm"
                                            placeholder="or paste URL"
                                            style="font-size:11px; margin-top:4px;">
                                        <div id="fb-holder2" style="max-height:36px; overflow:hidden; margin-top:4px;"></div>
                                        {{-- Size slider --}}
                                        <div style="display:flex; align-items:center; gap:5px; margin-top:4px;">
                                            <small style="font-size:10px; color:#888; white-space:nowrap;">Size</small>
                                            <input type="range" id="fb-img2-scale" min="20" max="200" value="100"
                                                style="flex:1; height:4px;">
                                            <span id="fb-img2-scale-label"
                                                style="font-size:10px; color:#666; min-width:30px; text-align:right;">100%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <div>
                                    <p class="align-middle p-0 m-0">{{ __('post.convert_font') }}
                                        <span style="color: red; ">*</span>
                                    </p>
                                </div>
                                <div class="col-12 pl-0">
                                    <div class="input inputMessage">
                                        <button type="button" id="replaceToGreekI" class="btn btn-warning col-5">
                                            Convet
                                        </button>
                                        <button type="button" id="replaceToLatinI" class="btn btn-info col-5">
                                            Revert
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <a href="{{ route('post.index') }}"
                                        class="btn button-center button-back btn-detail-custom">{{ __('admin.back') }}
                                    </a>
                                    <button type="submit"
                                    class="btn button-right button-create-update" id="edit_form">
                                        {{ __('post.update') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
            </div>
        </form>
    </section>
@endsection
