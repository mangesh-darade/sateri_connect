/**
 * Cheerio-style visual workflow canvas for Automations.
 */
(function (window, $) {
    'use strict';

    var Flow = {
        nodes: [],
        edges: [],
        selectedId: null,
        selectedEdge: null,
        dragNode: null,
        linkFrom: null,
        pan: null,
        spaceDown: false,
        scale: 1,
        panX: 0,
        panY: 0,
        uid: 1,
        meta: { tags: [], templates: [], agents: [], attributes: [], webhook_base: '' }
    };

    var GRID = 20;
    var MIN_SCALE = 0.4;
    var MAX_SCALE = 1.8;

    var TRIGGER_LABELS = {
        incoming_message: 'Incoming WhatsApp',
        campaign_sent: 'Campaign Sent',
        shopify_event: 'Shopify Events',
        facebook_lead: 'Facebook Lead',
        kylas_event_create: 'Kylas Event Create',
        kylas_event_update: 'Kylas Event Update',
        pabbly_event: 'Pabbly Event',
        incoming_webhook: 'Incoming Webhook',
        messenger: 'Messenger',
        instagram: 'Instagram',
        commerce_event: 'Commerce Event',
        contact_created: 'New contact',
        form_response: 'New form response',
        keyword_matched: 'Keyword matched',
        tag_added: 'Tag added',
        birthday: 'Birthday',
        campaign_replied: 'Campaign reply',
        schedule: 'Schedule',
        cheerio_workflow: 'Imported Cheerio workflow'
    };

    var ACTION_LABELS = {
        system_initiated: 'System initiated',
        response_message: 'Response message',
        collect_images: 'Collect Images',
        send_template: 'Send WA template',
        send_text: 'Send text',
        send_email: 'Send email',
        add_tag: 'Add tag',
        remove_tag: 'Remove tag',
        assign_agent: 'Assign agent',
        assign_bot: 'Assign to bot',
        update_chat_status: 'Update chat status',
        add_note: 'Add note',
        delay: 'Delay',
        webhook: 'Webhook',
        webhook_call: 'Webhook',
        set_attribute: 'Update attribute',
        end: 'End'
    };

    var CONDITION_LABELS = {
        message_contains: 'Message contains',
        message_equals: 'Message equals',
        caption_contains: 'Caption contains',
        message_type: 'Message type (text/image/video…)',
        has_tag: 'Has tag',
        within_window: 'Within 24h window',
        contact_status: 'Contact status',
        attribute_condition: 'Attribute condition'
    };

    var TRIGGER_CONFIG_KEYS = [
        'keyword', 'content', 'event_topic', 'event_type', 'shopify_topic',
        'form_id', 'ad_id', 'page_id', 'token', 'secret', 'campaign_id',
        'source', 'object', 'filter'
    ];

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function nextId(prefix) {
        return prefix + '_' + (Flow.uid++) + '_' + Date.now().toString(36);
    }

    function findNode(id) {
        return Flow.nodes.find(function (n) { return n.id === id; });
    }

    function loadGraph() {
        try {
            var raw = $('#flowGraphData').text() || $('#flowGraphJson').val() || '{}';
            var g = JSON.parse(raw);
            Flow.nodes = Array.isArray(g.nodes) ? g.nodes : [];
            Flow.edges = Array.isArray(g.edges) ? g.edges : [];
            Flow.nodes.forEach(function (n) {
                if ((n.x === undefined || n.y === undefined) && n.position) {
                    n.x = n.position.x || 40;
                    n.y = n.position.y || 40;
                }
                n.x = Number(n.x);
                n.y = Number(n.y);
                if (!isFinite(n.x)) n.x = 40;
                if (!isFinite(n.y)) n.y = 40;
                var m = String(n.id || '').match(/(\d+)/);
                if (m) Flow.uid = Math.max(Flow.uid, parseInt(m[1], 10) + 1);
            });
            // Only shift when Cheerio-style negative coords remain
            if (Flow.nodes.length) {
                var minX = Math.min.apply(null, Flow.nodes.map(function (n) { return n.x; }));
                var minY = Math.min.apply(null, Flow.nodes.map(function (n) { return n.y; }));
                if (minX < 0 || minY < 0) {
                    var sx = 60 - minX;
                    var sy = 60 - minY;
                    Flow.nodes.forEach(function (n) {
                        n.x = Math.round((n.x + sx) * 100) / 100;
                        n.y = Math.round((n.y + sy) * 100) / 100;
                    });
                }
            }
            Flow.edges = Flow.edges.map(function (e) {
                var handle = e.port || e.sourceHandle || 'out';
                var port = 'out';
                var hs = String(handle);
                if (handle === 'false' || hs.indexOf('child_node_1') >= 0 || /_no$/i.test(hs)) {
                    port = 'false';
                } else if (handle === 'true' || hs.indexOf('child_node_0') >= 0 || /_yes$/i.test(hs)) {
                    port = 'true';
                } else {
                    var cm = hs.match(/child_node_(\d+)/i);
                    if (cm) {
                        var idx = parseInt(cm[1], 10);
                        port = idx === 0 ? 'true' : (idx === 1 ? 'false' : ('opt_' + idx));
                    } else if (handle && handle !== 'out' && handle !== 'source') {
                        port = hs;
                    }
                }
                return { from: e.from || e.source, to: e.to || e.target, port: port };
            }).filter(function (e) { return e.from && e.to; });
        } catch (e) {
            Flow.nodes = [];
            Flow.edges = [];
        }
        try {
            Flow.meta = JSON.parse($('#flowMetaJson').text() || '{}') || Flow.meta;
        } catch (e2) {
            // ignore
        }
        if (!Flow.nodes.length) {
            Flow.nodes.push({
                id: 'trigger',
                type: 'trigger',
                x: 60,
                y: 180,
                data: { trigger_type: 'incoming_message', label: TRIGGER_LABELS.incoming_message }
            });
        }
    }

    function nodeTitle(node) {
        var d = node.data || {};
        var label = d.label ? String(d.label).replace(/\\\//g, '/') : '';
        var generic = !label || /\snode$/i.test(label);

        if (!generic && (node.type === 'trigger' || node.type === 'action' || node.type === 'condition' || node.type === 'end')) {
            return label;
        }
        if (node.type === 'trigger') {
            return TRIGGER_LABELS[d.trigger_type] || label || d.trigger_type || 'Trigger';
        }
        if (node.type === 'condition') return CONDITION_LABELS[d.condition_type] || label || 'If / Else';
        if (node.type === 'end') return 'End';
        if (d.adName || d.labelName) return String(d.adName || d.labelName).replace(/\\\//g, '/');
        if (d.attribute) {
            var v = String(d.attributeNewValue || d.value || d.text || '').replace(/\\\//g, '/');
            return 'Update ' + d.attribute + (v ? ' = ' + v : '');
        }
        if (d.url && (d.action_type === 'webhook' || String(d.cheerio_type || '').toLowerCase().indexOf('webhook') >= 0)) {
            return 'Webhook: ' + String(d.url).slice(0, 42);
        }
        return ACTION_LABELS[d.action_type] || d.action_type || label || d.cheerio_type || 'Action';
    }

    function nodeSubtitle(node) {
        var d = node.data || {};
        if (node.type === 'trigger') {
            if (d.trigger_type === 'keyword_matched' || d.keyword) return d.keyword ? ('“' + d.keyword + '”') : 'Any keyword…';
            if (d.trigger_type === 'shopify_event') return d.shopify_topic || d.event_topic || 'Any topic…';
            if (d.trigger_type === 'commerce_event') return d.event_type || 'Any event…';
            if (d.trigger_type === 'kylas_event_create' || d.trigger_type === 'kylas_event_update') return d.object || d.event_type || 'Any object…';
            if (d.trigger_type === 'pabbly_event') return d.event_type || 'Any event…';
            if (d.trigger_type === 'incoming_webhook') return d.token ? ('Token …' + String(d.token).slice(-6)) : 'Set token…';
            if (d.trigger_type === 'facebook_lead') return d.form_id || d.ad_id || 'Any lead form…';
            if (d.trigger_type === 'form_response') return d.form_id || 'Any form…';
            if (d.trigger_type === 'campaign_sent' || d.trigger_type === 'campaign_replied') return d.campaign_id ? ('Campaign #' + d.campaign_id) : 'Any campaign…';
            if (d.trigger_type === 'messenger' || d.trigger_type === 'instagram') return d.page_id || 'Any page…';
            return '';
        }
        if (node.type === 'condition') return d.value ? ('“' + d.value + '”') : 'Configure…';
        if (d.action_type === 'response_message' || d.action_type === 'send_text') {
            return d.text ? String(d.text).slice(0, 42) : 'Write message…';
        }
        if (d.action_type === 'system_initiated' || d.action_type === 'send_template') {
            return d.template_name || 'Pick template…';
        }
        if (d.action_type === 'collect_images') {
            return (d.count || d.max_images || 1) + ' image(s)';
        }
        if (d.action_type === 'add_tag' || d.action_type === 'remove_tag') {
            return d.tag_name || d.labelName || (d.tag_id ? ('Tag #' + d.tag_id) : 'Pick tag…');
        }
        if (d.action_type === 'delay') return (d.minutes || Math.round((d.seconds || 60) / 60)) + ' min';
        if (d.action_type === 'webhook' || d.action_type === 'webhook_call') {
            var n = Array.isArray(d.values) ? d.values.length : 0;
            return (d.url ? String(d.url).replace(/^https?:\/\//, '').slice(0, 28) : 'URL…') + (n ? (' · ' + n + ' params') : '');
        }
        if (d.action_type === 'set_attribute') return (d.attribute || '') + (d.text ? (' = ' + d.text) : '');
        if (node.type === 'end' || d.action_type === 'end') return '';
        if (d.cheerio_type) return String(d.cheerio_type);
        return (!d.label || /\snode$/i.test(String(d.label))) ? '' : String(d.label);
    }

    function portsForNode(node) {
        var ports = {};
        Flow.edges.forEach(function (e) {
            if (e.from === node.id) ports[e.port || 'out'] = true;
        });
        var d = node.data || {};
        if (Array.isArray(d.ports)) {
            d.ports.forEach(function (p) { ports[p] = true; });
        }
        if (node.type === 'condition' || d.branches) {
            if (!ports.true && !ports.false && !Object.keys(ports).some(function (p) { return String(p).indexOf('opt_') === 0; })) {
                ports.true = true;
                ports.false = true;
            }
        }
        var list = Object.keys(ports);
        if (!list.length && node.type !== 'end') list = ['out'];
        var order = { true: 1, false: 2, out: 3 };
        list.sort(function (a, b) {
            var ao = order[a] || (String(a).indexOf('opt_') === 0 ? 10 + parseInt(String(a).slice(4), 10) : 50);
            var bo = order[b] || (String(b).indexOf('opt_') === 0 ? 10 + parseInt(String(b).slice(4), 10) : 50);
            return ao - bo;
        });
        return list;
    }

    function nodeHasBranches(node) {
        if (!node) return false;
        if (node.type === 'condition') return true;
        var d = node.data || {};
        if (d.branches) return true;
        return portsForNode(node).some(function (p) {
            return p === 'true' || p === 'false' || String(p).indexOf('opt_') === 0;
        });
    }

    function snap(v) {
        return Math.round(v / GRID) * GRID;
    }

    function applyViewport() {
        var vp = document.getElementById('flowViewport');
        if (!vp) return;
        vp.style.transform = 'translate(' + Flow.panX + 'px,' + Flow.panY + 'px) scale(' + Flow.scale + ')';
        var label = document.getElementById('flowZoomLabel');
        if (label) label.textContent = Math.round(Flow.scale * 100) + '%';
    }

    function setScale(next, cx, cy) {
        var wrap = document.getElementById('flowCanvasWrap') || document.querySelector('.flow-canvas-wrap');
        var old = Flow.scale;
        next = Math.max(MIN_SCALE, Math.min(MAX_SCALE, next));
        if (wrap && cx != null && cy != null) {
            var rect = wrap.getBoundingClientRect();
            var mx = cx - rect.left;
            var my = cy - rect.top;
            Flow.panX = mx - (mx - Flow.panX) * (next / old);
            Flow.panY = my - (my - Flow.panY) * (next / old);
        }
        Flow.scale = next;
        applyViewport();
        drawEdges();
    }

    function fitView() {
        var wrap = document.getElementById('flowCanvasWrap') || document.querySelector('.flow-canvas-wrap');
        if (!wrap || !Flow.nodes.length) return;
        var minX = Math.min.apply(null, Flow.nodes.map(function (n) { return n.x; }));
        var minY = Math.min.apply(null, Flow.nodes.map(function (n) { return n.y; }));
        var maxX = Math.max.apply(null, Flow.nodes.map(function (n) { return n.x + 220; }));
        var maxY = Math.max.apply(null, Flow.nodes.map(function (n) { return n.y + 100; }));
        var bw = maxX - minX + 80;
        var bh = maxY - minY + 80;
        var rw = Math.max(200, wrap.clientWidth - 24);
        var rh = Math.max(200, wrap.clientHeight - 40);
        var s = Math.min(1.1, rw / bw, rh / bh);
        s = Math.max(MIN_SCALE, Math.min(MAX_SCALE, s));
        Flow.scale = s;
        Flow.panX = (rw - bw * s) / 2 - (minX - 40) * s;
        Flow.panY = (rh - bh * s) / 2 - (minY - 40) * s;
        applyViewport();
        drawEdges();
    }

    function resizeCanvas() {
        var maxX = 1200;
        var maxY = 800;
        Flow.nodes.forEach(function (n) {
            maxX = Math.max(maxX, (Number(n.x) || 0) + 320);
            maxY = Math.max(maxY, (Number(n.y) || 0) + 220);
        });
        var w = Math.ceil(maxX);
        var h = Math.ceil(maxY);
        var canvas = document.getElementById('flowCanvas');
        var edges = document.getElementById('flowEdges');
        var vp = document.getElementById('flowViewport');
        if (canvas) {
            canvas.style.width = w + 'px';
            canvas.style.height = h + 'px';
        }
        if (edges) {
            edges.setAttribute('width', String(w));
            edges.setAttribute('height', String(h));
            edges.style.width = w + 'px';
            edges.style.height = h + 'px';
        }
        if (vp) {
            vp.style.width = w + 'px';
            vp.style.height = h + 'px';
        }
    }

    function renderNodes() {
        resizeCanvas();
        var $c = $('#flowCanvas').empty();
        Flow.nodes.forEach(function (node) {
            var ports = '';
            if (node.type !== 'trigger') {
                ports += '<div class="flow-port in" data-port="in" data-node="' + esc(node.id) + '"></div>';
            }
            var outPorts = portsForNode(node);
            var branched = outPorts.length > 1 || outPorts.indexOf('true') >= 0 || outPorts.indexOf('false') >= 0
                || outPorts.some(function (p) { return String(p).indexOf('opt_') === 0; });
            if (branched) {
                var n = outPorts.length || 2;
                outPorts.forEach(function (p, i) {
                    var top = Math.round(((i + 1) / (n + 1)) * 100);
                    var cls = p === 'true' ? 'true' : (p === 'false' ? 'false' : 'out');
                    ports += '<div class="flow-port ' + cls + '" data-port="' + esc(p) + '" data-node="' + esc(node.id) + '" style="top:' + top + '%;transform:translateY(-50%)"></div>';
                    var lbl = p === 'true' ? 'Yes' : (p === 'false' ? 'No' : (String(p).indexOf('opt_') === 0 ? ('#' + String(p).slice(4)) : 'Out'));
                    ports += '<span class="flow-port-label" style="top:' + Math.max(8, top - 6) + '%">' + esc(lbl) + '</span>';
                });
            } else if (node.type !== 'end') {
                ports += '<div class="flow-port out" data-port="out" data-node="' + esc(node.id) + '"></div>';
            }
            var typeLabel = node.type === 'updateAttribute' ? 'action' : node.type;
            var html =
                '<div class="flow-node ' + esc(node.type) + (Flow.selectedId === node.id ? ' selected' : '') + '" data-id="' + esc(node.id) + '" style="left:' + node.x + 'px;top:' + node.y + 'px">' +
                '<div class="flow-node-head"><i class="fas fa-' + (node.type === 'trigger' ? 'bolt' : node.type === 'condition' ? 'code-branch' : node.type === 'end' ? 'flag-checkered' : 'play') + '"></i> ' + esc(typeLabel) + '</div>' +
                '<div class="flow-node-body">' + esc(nodeTitle(node)) + '<span class="flow-node-sub">' + esc(nodeSubtitle(node)) + '</span></div>' +
                ports +
                '</div>';
            $c.append(html);
        });
        drawEdges();
    }

    function portCenter(nodeId, port) {
        var portAttr = String(port);
        try {
            if (window.CSS && typeof CSS.escape === 'function') {
                portAttr = CSS.escape(portAttr);
            }
        } catch (err) { /* ignore */ }
        var el = document.querySelector('.flow-port[data-node="' + nodeId + '"][data-port="' + portAttr + '"]');
        if (!el) {
            el = document.querySelector('.flow-port[data-node="' + nodeId + '"][data-port="' + String(port) + '"]');
        }
        var canvas = document.getElementById('flowCanvas');
        if (!el || !canvas) {
            var nodeEl = document.querySelector('.flow-node[data-id="' + nodeId + '"]');
            if (!nodeEl || !canvas) return null;
            var nr = nodeEl.getBoundingClientRect();
            var cr0 = canvas.getBoundingClientRect();
            var s0 = Flow.scale || 1;
            return {
                x: (nr.right - cr0.left) / s0,
                y: (nr.top - cr0.top) / s0 + (nr.height / s0) / 2
            };
        }
        var er = el.getBoundingClientRect();
        var cr = canvas.getBoundingClientRect();
        var s = Flow.scale || 1;
        return {
            x: (er.left - cr.left) / s + (er.width / s) / 2,
            y: (er.top - cr.top) / s + (er.height / s) / 2
        };
    }

    function curve(x1, y1, x2, y2) {
        var dx = Math.max(70, Math.abs(x2 - x1) * 0.5);
        return 'M ' + x1 + ' ' + y1 + ' C ' + (x1 + dx) + ' ' + y1 + ', ' + (x2 - dx) + ' ' + y2 + ', ' + x2 + ' ' + y2;
    }

    function clearEdgeSvg(svg) {
        var keep = [];
        Array.prototype.slice.call(svg.childNodes).forEach(function (child) {
            if (child.nodeName && child.nodeName.toLowerCase() === 'defs') {
                keep.push(child);
            } else {
                svg.removeChild(child);
            }
        });
        if (!svg.querySelector('defs')) {
            var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            defs.innerHTML =
                '<marker id="flowArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="rgba(7, 94, 84, 0.45)"></path></marker>' +
                '<marker id="flowArrowTrue" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#8e53f7"></path></marker>' +
                '<marker id="flowArrowFalse" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#e25555"></path></marker>';
            svg.appendChild(defs);
        }
    }

    function edgeKey(e) {
        return String(e.from) + '|' + String(e.port || 'out') + '|' + String(e.to);
    }

    function drawEdges(temp) {
        var svg = document.getElementById('flowEdges');
        if (!svg) return;
        clearEdgeSvg(svg);

        Flow.edges.forEach(function (e, idx) {
            var fromPort = e.port || 'out';
            if (fromPort === 'in') fromPort = 'out';
            var a = portCenter(e.from, fromPort);
            if (!a) a = portCenter(e.from, 'out');
            if (!a) a = portCenter(e.from, 'true');
            var toNode = findNode(e.to);
            var b = portCenter(e.to, toNode && toNode.type === 'trigger' ? 'out' : 'in');
            if (!b) b = portCenter(e.to, 'out');
            if (!a || !b) return;

            var key = edgeKey(e);
            var hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            hit.setAttribute('d', curve(a.x, a.y, b.x, b.y));
            hit.setAttribute('class', 'flow-edge-hit');
            hit.setAttribute('data-edge-idx', String(idx));
            hit.setAttribute('data-edge-key', key);
            svg.appendChild(hit);

            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', curve(a.x, a.y, b.x, b.y));
            var edgeCls = 'flow-edge';
            var marker = 'url(#flowArrow)';
            if (e.port === 'true') { edgeCls += ' true'; marker = 'url(#flowArrowTrue)'; }
            else if (e.port === 'false') { edgeCls += ' false'; marker = 'url(#flowArrowFalse)'; }
            if (Flow.selectedEdge && Flow.selectedEdge === key) edgeCls += ' selected';
            path.setAttribute('class', edgeCls);
            path.setAttribute('marker-end', marker);
            path.setAttribute('data-edge-idx', String(idx));
            path.setAttribute('data-edge-key', key);
            svg.appendChild(path);
        });

        if (temp && temp.a && temp.b) {
            var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            p.setAttribute('d', curve(temp.a.x, temp.a.y, temp.b.x, temp.b.y));
            p.setAttribute('class', 'flow-edge temp');
            svg.appendChild(p);
        }
    }

    function selectNode(id) {
        Flow.selectedId = id;
        renderNodes();
        renderInspector();
    }

    function inspHint(text) {
        return '<p class="insp-hint">' + esc(text) + '</p>';
    }

    function templateSelectHtml(d) {
        var html = '<label>WA template</label><select class="form-select insp" data-k="template_name"><option value="">—</option>';
        (Flow.meta.templates || []).forEach(function (t) {
            html += '<option value="' + esc(t.name) + '"' + (d.template_name === t.name ? ' selected' : '') + '>' + esc(t.name) + ' (' + esc(t.language) + ')</option>';
        });
        html += '</select>';
        html += '<label>Language</label><input class="form-control insp" data-k="language" value="' + esc(d.language || 'en_US') + '">';
        return html;
    }

    function tagSelectHtml(d) {
        var html = '<label>Tag</label><select class="form-select insp" data-k="tag_id"><option value="">—</option>';
        (Flow.meta.tags || []).forEach(function (t) {
            html += '<option value="' + t.id + '"' + (String(d.tag_id) === String(t.id) ? ' selected' : '') + '>' + esc(t.name) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function agentSelectHtml(d) {
        var html = '<label>Assign agent</label><select class="form-select insp" data-k="user_id"><option value="">—</option>';
        (Flow.meta.agents || []).forEach(function (a) {
            html += '<option value="' + a.id + '"' + (String(d.user_id || d.agent_id) === String(a.id) ? ' selected' : '') + '>' + esc(a.name) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function knownAttributes(extra) {
        var set = {};
        (Flow.meta.attributes || []).forEach(function (k) { if (k) set[String(k)] = true; });
        (extra || []).forEach(function (k) { if (k) set[String(k)] = true; });
        return Object.keys(set).sort(function (a, b) {
            return a.toLowerCase().localeCompare(b.toLowerCase());
        });
    }

    function attributeSelectHtml(d) {
        var cur = d.attribute || '';
        var html = '<label>Attribute</label><input class="form-control insp" data-k="attribute" list="inspSetAttrList" value="' + esc(cur) + '" placeholder="e.g. source, name, city">';
        html += '<datalist id="inspSetAttrList">';
        knownAttributes([cur]).forEach(function (k) {
            html += '<option value="' + esc(k) + '">';
        });
        html += '</datalist>';
        return html;
    }

    function webhookHeaderHtml(d) {
        var h = d.header;
        if (!h || typeof h !== 'object' || Array.isArray(h)) {
            h = { key: 'Content-Type', value: 'application/json' };
        }
        var html = '<label class="mt-2">Header name</label><input class="form-control insp-header" data-hk="key" value="' + esc(h.key || '') + '" placeholder="Content-Type">';
        html += '<label>Header value</label><input class="form-control insp-header" data-hk="value" value="' + esc(h.value || '') + '" placeholder="application/json">';
        return html;
    }

    function webhookValuesHtml(d) {
        var selected = Array.isArray(d.values) ? d.values.map(String) : [];
        var keys = knownAttributes(selected);
        var html = '<div class="mt-2"><label>Parameter mapping</label>';
        html += '<div class="insp-attr-map" style="max-height:180px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px">';
        if (!keys.length) {
            html += '<p class="text-muted small mb-0">No attributes yet — add custom below.</p>';
        }
        keys.forEach(function (k, idx) {
            var id = 'wv_' + idx + '_' + k.replace(/[^a-z0-9_]/gi, '_');
            var checked = selected.indexOf(k) >= 0 ? ' checked' : '';
            html += '<div class="form-check"><input type="checkbox" class="form-check-input insp-value-check" data-attr="' + esc(k) + '" id="' + id + '"' + checked + '>';
            html += '<label class="form-check-label" for="' + id + '">' + esc(k) + '</label></div>';
        });
        html += '</div>';
        html += '<div class="input-group input-group-sm mt-2">';
        html += '<input type="text" class="form-control" id="inspNewParam" list="inspAttrList" placeholder="Add param key">';
        html += '<button type="button" class="btn btn-outline-secondary" id="inspAddParam">Add</button>';
        html += '</div>';
        html += '<datalist id="inspAttrList">';
        keys.forEach(function (k) { html += '<option value="' + esc(k) + '">'; });
        html += '</datalist>';
        if (selected.length) {
            html += '<p class="insp-hint mb-0 mt-1">Sending: ' + esc(selected.join(', ')) + '</p>';
        }
        html += '</div>';
        return html;
    }

    function syncWebhookValuesFromChecks() {
        var node = findNode(Flow.selectedId);
        if (!node) return;
        node.data = node.data || {};
        var values = [];
        $('#inspectorBody .insp-value-check:checked').each(function () {
            var a = String($(this).data('attr') || '');
            if (a && values.indexOf(a) < 0) values.push(a);
        });
        node.data.values = values;
        renderNodes();
        $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
    }

    function renderTriggerFields(d) {
        var t = d.trigger_type || 'incoming_message';
        var html = '';

        if (t === 'incoming_message' || t === 'keyword_matched' || t === 'messenger' || t === 'instagram') {
            html += '<label>Optional keyword / filter</label><input class="form-control insp" data-k="keyword" value="' + esc(d.keyword || '') + '" placeholder="e.g. hello">';
            html += inspHint('Leave blank to match any inbound message on this channel.');
        }
        if (t === 'campaign_sent' || t === 'campaign_replied') {
            html += '<label>Campaign ID (optional)</label><input class="form-control insp" data-k="campaign_id" value="' + esc(d.campaign_id || '') + '" placeholder="Leave blank = any">';
        }
        if (t === 'shopify_event') {
            html += '<label>Shopify topic</label><select class="form-select insp" data-k="shopify_topic">';
            ['', 'orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'customers/create', 'customers/update', 'checkouts/create', 'fulfillments/create'].forEach(function (v) {
                html += '<option value="' + v + '"' + ((d.shopify_topic || '') === v ? ' selected' : '') + '>' + (v || 'Any topic') + '</option>';
            });
            html += '</select>';
            html += '<label>Or custom topic</label><input class="form-control insp" data-k="event_topic" value="' + esc(d.event_topic || '') + '" placeholder="custom/topic">';
        }
        if (t === 'facebook_lead') {
            html += '<label>Lead form ID</label><input class="form-control insp" data-k="form_id" value="' + esc(d.form_id || '') + '">';
            html += '<label>Ad ID (optional)</label><input class="form-control insp" data-k="ad_id" value="' + esc(d.ad_id || '') + '">';
            html += '<label>Page ID (optional)</label><input class="form-control insp" data-k="page_id" value="' + esc(d.page_id || '') + '">';
        }
        if (t === 'kylas_event_create' || t === 'kylas_event_update') {
            html += '<label>Object type</label><select class="form-select insp" data-k="object">';
            ['', 'lead', 'deal', 'contact', 'company', 'activity'].forEach(function (v) {
                html += '<option value="' + v + '"' + ((d.object || '') === v ? ' selected' : '') + '>' + (v || 'Any object') + '</option>';
            });
            html += '</select>';
            html += '<label>Event type filter</label><input class="form-control insp" data-k="event_type" value="' + esc(d.event_type || '') + '" placeholder="optional">';
        }
        if (t === 'pabbly_event') {
            html += '<label>Event type</label><input class="form-control insp" data-k="event_type" value="' + esc(d.event_type || '') + '" placeholder="e.g. new_lead">';
            html += '<label>Source filter</label><input class="form-control insp" data-k="source" value="' + esc(d.source || '') + '">';
        }
        if (t === 'incoming_webhook') {
            html += '<label>Webhook token</label><input class="form-control insp" data-k="token" value="' + esc(d.token || '') + '" placeholder="secret token">';
            html += '<label>Optional secret</label><input class="form-control insp" data-k="secret" value="' + esc(d.secret || '') + '">';
            if (Flow.meta.webhook_base) {
                html += inspHint('POST to ' + Flow.meta.webhook_base + '/{token}');
            } else {
                html += inspHint('Each automation can listen on a unique webhook token.');
            }
        }
        if (t === 'messenger' || t === 'instagram') {
            html += '<label>Page / IG account ID</label><input class="form-control insp" data-k="page_id" value="' + esc(d.page_id || '') + '">';
        }
        if (t === 'commerce_event') {
            html += '<label>Event type</label><select class="form-select insp" data-k="event_type">';
            ['', 'order_created', 'order_updated', 'payment_received', 'cart_abandoned', 'product_inquiry'].forEach(function (v) {
                html += '<option value="' + v + '"' + ((d.event_type || '') === v ? ' selected' : '') + '>' + (v || 'Any event') + '</option>';
            });
            html += '</select>';
        }
        if (t === 'form_response') {
            html += '<label>Form ID</label><input class="form-control insp" data-k="form_id" value="' + esc(d.form_id || '') + '" placeholder="Leave blank = any form">';
        }
        if (t === 'contact_created') {
            html += inspHint('Fires when a new contact is created (WhatsApp inbound or manual).');
        }
        if (t === 'tag_added') {
            html += tagSelectHtml(d);
            html += inspHint('Optional: only when this specific tag is added.');
        }
        if (t === 'birthday' || t === 'schedule') {
            html += inspHint('Processed by the daily automations cron.');
        }

        if (!html) {
            html += inspHint('No extra filters for this trigger.');
        }
        return html;
    }

    function renderActionFields(d) {
        var a = d.action_type || 'response_message';
        var html = '';

        if (a === 'system_initiated') {
            html += inspHint('Business-initiated outreach (outside 24h window) via approved WA template.');
            html += templateSelectHtml(d);
            html += '<label>Fallback note (optional)</label><textarea class="form-control insp" data-k="text" rows="2">' + esc(d.text || '') + '</textarea>';
        } else if (a === 'response_message' || a === 'send_text') {
            html += '<label>Message text</label><textarea class="form-control insp" data-k="text" rows="4" placeholder="Hi {{contact.name}}!">' + esc(d.text || d.note || '') + '</textarea>';
            html += inspHint('Use {{contact.name}}, {{contact.mobile}} placeholders.');
        } else if (a === 'collect_images') {
            html += '<label>How many images?</label><input type="number" min="1" max="20" class="form-control insp" data-k="count" value="' + esc(d.count || d.max_images || 1) + '">';
            html += '<label>Prompt message</label><textarea class="form-control insp" data-k="prompt" rows="3" placeholder="Please send your photo…">' + esc(d.prompt || d.text || '') + '</textarea>';
            html += inspHint('Asks the contact for images, then stores them on the contact until the count is met.');
        } else if (a === 'send_template') {
            html += templateSelectHtml(d);
        } else if (a === 'add_tag' || a === 'remove_tag') {
            html += tagSelectHtml(d);
            html += '<label>Or tag name</label><input class="form-control insp" data-k="tag_name" value="' + esc(d.tag_name || d.labelName || '') + '" placeholder="Tag / label name">';
        } else if (a === 'set_attribute') {
            html += attributeSelectHtml(d);
            html += '<label>New value</label><input class="form-control insp" data-k="text" value="' + esc(d.text || d.attributeNewValue || '') + '" placeholder="Value or {{contact.name}}">';
            html += inspHint('Core fields (name, mobile, email…) update the contact row; others go into custom attributes.');
        } else if (a === 'assign_agent') {
            html += agentSelectHtml(d);
        } else if (a === 'assign_bot') {
            html += inspHint('Marks the conversation as Chatbot and clears the human assignee.');
        } else if (a === 'update_chat_status') {
            html += '<label>Chat status</label><select class="form-select insp" data-k="status">';
            ['open', 'pending', 'resolved', 'chatbot', 'intervened'].forEach(function (s) {
                html += '<option value="' + s + '"' + ((d.status || 'open') === s ? ' selected' : '') + '>' + s + '</option>';
            });
            html += '</select>';
        } else if (a === 'send_email') {
            html += '<label>To (optional)</label><input class="form-control insp" data-k="to" value="' + esc(d.to || '') + '" placeholder="Leave blank = contact email">';
            html += '<label>Subject</label><input class="form-control insp" data-k="subject" value="' + esc(d.subject || '') + '">';
            html += '<label>Body</label><textarea class="form-control insp" data-k="text" rows="4">' + esc(d.text || d.body || '') + '</textarea>';
            html += inspHint('Uses the active Email provider. Placeholders: {{contact.name}}, {{contact.email}}.');
        } else if (a === 'add_note') {
            html += '<label>Note</label><textarea class="form-control insp" data-k="text" rows="3">' + esc(d.text || d.note || '') + '</textarea>';
        } else if (a === 'delay') {
            html += '<label>Delay (minutes)</label><input type="number" min="1" class="form-control insp" data-k="minutes" value="' + esc(d.minutes || Math.round((d.seconds || 60) / 60) || 1) + '">';
        } else if (a === 'webhook' || a === 'webhook_call') {
            html += '<label>Webhook URL</label><input class="form-control insp" data-k="url" value="' + esc(d.url || '') + '" placeholder="https://…">';
            html += '<label>Method</label><select class="form-select insp" data-k="method">';
            ['POST', 'GET', 'PUT', 'PATCH'].forEach(function (m) {
                html += '<option value="' + m + '"' + ((d.method || 'POST') === m ? ' selected' : '') + '>' + m + '</option>';
            });
            html += '</select>';
            html += webhookHeaderHtml(d);
            html += webhookValuesHtml(d);
            html += '<div class="form-check mt-2"><input type="checkbox" class="form-check-input insp-check" data-k="branches" id="inspBranches"' + (d.branches ? ' checked' : '') + '><label class="form-check-label" for="inspBranches">Branch on success / fail</label></div>';
            html += inspHint('Mapped params are sent as JSON body from contact attributes. Use {{contact.name}} in URL/header.');
        } else if (a === 'end') {
            html += inspHint('Stops the workflow here.');
        } else {
            html += '<label>Text / note</label><textarea class="form-control insp" data-k="text" rows="3">' + esc(d.text || d.note || '') + '</textarea>';
            html += templateSelectHtml(d);
            html += tagSelectHtml(d);
            html += agentSelectHtml(d);
            html += '<label>Delay (minutes)</label><input type="number" min="1" class="form-control insp" data-k="minutes" value="' + esc(d.minutes || 1) + '">';
            html += '<label>Webhook URL</label><input class="form-control insp" data-k="url" value="' + esc(d.url || '') + '">';
        }
        return html;
    }

    function renderInspector() {
        var node = findNode(Flow.selectedId);
        var $body = $('#inspectorBody');
        var $empty = $('#inspectorEmpty');
        var $del = $('#btnDeleteNode');
        if (!node) {
            $body.addClass('d-none').empty();
            $empty.removeClass('d-none');
            $del.addClass('d-none');
            return;
        }
        $empty.addClass('d-none');
        $body.removeClass('d-none');
        $del.toggleClass('d-none', node.type === 'trigger' && Flow.nodes.filter(function (n) { return n.type === 'trigger'; }).length <= 1);

        var d = node.data || {};
        var html = '';

        if (node.type === 'trigger') {
            html += '<label>Trigger</label><select class="form-select insp" data-k="trigger_type">';
            Object.keys(TRIGGER_LABELS).forEach(function (k) {
                html += '<option value="' + k + '"' + (d.trigger_type === k ? ' selected' : '') + '>' + TRIGGER_LABELS[k] + '</option>';
            });
            html += '</select>';
            html += renderTriggerFields(d);
        } else if (node.type === 'condition') {
            html += '<label>Condition</label><select class="form-select insp" data-k="condition_type">';
            Object.keys(CONDITION_LABELS).forEach(function (k) {
                html += '<option value="' + k + '"' + (d.condition_type === k ? ' selected' : '') + '>' + CONDITION_LABELS[k] + '</option>';
            });
            html += '</select>';
            if (d.condition_type === 'message_type') {
                html += '<label>Message type</label><select class="form-select insp" data-k="value">';
                ['text', 'image', 'video', 'document', 'audio', 'button', 'interactive'].forEach(function (t) {
                    html += '<option value="' + t + '"' + ((d.value || 'text') === t ? ' selected' : '') + '>' + t + '</option>';
                });
                html += '</select>';
                html += inspHint('True when inbound WhatsApp message_type matches (image/video captions still fill content).');
            } else if (d.condition_type === 'caption_contains') {
                html += '<label>Caption contains</label><input class="form-control insp" data-k="value" value="' + esc(d.value || '') + '">';
                html += inspHint('Matches text OR media caption (image/video/document).');
            } else if (d.condition_type === 'attribute_condition') {
                html += attributeSelectHtml(d);
                html += '<label>Operator</label><select class="form-select insp" data-k="operator">';
                [
                    ['equals', 'Equals'],
                    ['not_equals', 'Not equals'],
                    ['contains', 'Contains'],
                    ['not_contains', 'Does not contain'],
                    ['empty', 'Is empty'],
                    ['not_empty', 'Is not empty']
                ].forEach(function (pair) {
                    html += '<option value="' + pair[0] + '"' + ((d.operator || 'equals') === pair[0] ? ' selected' : '') + '>' + pair[1] + '</option>';
                });
                html += '</select>';
                html += '<label>Value</label><input class="form-control insp" data-k="value" value="' + esc(d.value || '') + '">';
            } else {
                html += '<label>Value</label><input class="form-control insp" data-k="value" value="' + esc(d.value || '') + '">';
            }
            html += tagSelectHtml(d);
        } else if (node.type === 'end') {
            html += inspHint('End node — workflow stops here.');
        } else {
            html += '<label>Action</label><select class="form-select insp" data-k="action_type">';
            Object.keys(ACTION_LABELS).forEach(function (k) {
                if (k === 'webhook_call' || k === 'end') return;
                html += '<option value="' + k + '"' + (d.action_type === k || (k === 'webhook' && d.action_type === 'webhook_call') ? ' selected' : '') + '>' + ACTION_LABELS[k] + '</option>';
            });
            html += '</select>';
            html += renderActionFields(d);
        }

        $body.html(html);
    }

    function applyInspector($el) {
        var node = findNode(Flow.selectedId);
        if (!node) return;
        node.data = node.data || {};
        var k = $el.data('k');
        var v = $el.val();
        node.data[k] = v;
        if (k === 'trigger_type') {
            node.data.label = TRIGGER_LABELS[v] || v;
            $('#flowTriggerPill').text(v);
            renderNodes();
            $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
            renderInspector();
            return;
        }
        if (k === 'condition_type') {
            node.data.label = CONDITION_LABELS[v] || v;
            renderNodes();
            $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
            renderInspector();
            return;
        }
        if (k === 'action_type') {
            node.data.label = ACTION_LABELS[v] || v;
            if (v === 'response_message' && !node.data.text) node.data.text = 'Hello {{contact.name}}!';
            if (v === 'collect_images' && !node.data.count) node.data.count = 1;
            if (v === 'delay' && !node.data.minutes) { node.data.minutes = 5; node.data.seconds = 300; }
            if ((v === 'webhook' || v === 'webhook_call') && !Array.isArray(node.data.values)) node.data.values = [];
            if ((v === 'webhook' || v === 'webhook_call') && (!node.data.header || typeof node.data.header !== 'object')) {
                node.data.header = { key: 'Content-Type', value: 'application/json' };
            }
            if ((v === 'webhook' || v === 'webhook_call') && node.data.branches === undefined) node.data.branches = true;
            renderNodes();
            $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
            renderInspector();
            return;
        }
        if (k === 'minutes') {
            node.data.seconds = parseInt(v, 10) * 60;
        }
        if (k === 'count') {
            node.data.max_images = parseInt(v, 10) || 1;
        }
        if (k === 'prompt') {
            node.data.text = v;
        }
        if (k === 'text' && node.data.action_type === 'set_attribute') {
            node.data.attributeNewValue = v;
        }
        if (k === 'attribute') {
            node.data.attribute = v;
        }
        if (k === 'template_name') {
            var tpl = (Flow.meta.templates || []).find(function (t) { return t.name === v; });
            if (tpl) node.data.language = tpl.language || node.data.language || 'en_US';
        }
        renderNodes();
        $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
    }

    function addNodeFromPalette(palette, extra, x, y) {
        var node = { id: nextId(palette), type: palette, x: x, y: y, data: {} };
        if (palette === 'trigger') {
            Flow.nodes = Flow.nodes.filter(function (n) { return n.type !== 'trigger'; });
            node.id = 'trigger';
            node.data.trigger_type = extra || 'incoming_message';
            node.data.label = TRIGGER_LABELS[node.data.trigger_type] || node.data.trigger_type;
            $('#flowTriggerPill').text(node.data.trigger_type);
        } else if (palette === 'condition') {
            node.data.condition_type = extra || 'message_contains';
            node.data.label = CONDITION_LABELS[node.data.condition_type] || 'If / Else';
            node.data.value = '';
        } else if (palette === 'end') {
            node.type = 'end';
            node.data.action_type = 'end';
            node.data.label = 'End';
        } else {
            node.data.action_type = extra || 'response_message';
            node.data.label = ACTION_LABELS[node.data.action_type] || node.data.action_type;
            if (node.data.action_type === 'response_message' || node.data.action_type === 'send_text') {
                node.data.text = 'Hello {{contact.name}}!';
            }
            if (node.data.action_type === 'collect_images') {
                node.data.count = 1;
                node.data.prompt = 'Please send your image.';
            }
            if (node.data.action_type === 'delay') {
                node.data.minutes = 5;
                node.data.seconds = 300;
            }
            if (node.data.action_type === 'webhook') {
                node.data.branches = true;
                node.data.values = [];
                node.data.header = { key: 'Content-Type', value: 'application/json' };
            }
            if (node.data.action_type === 'set_attribute') {
                node.data.attribute = '';
                node.data.text = '';
            }
        }
        Flow.nodes.push(node);
        selectNode(node.id);
    }

    function deleteSelected() {
        if (Flow.selectedEdge) {
            var parts = Flow.selectedEdge.split('|');
            Flow.edges = Flow.edges.filter(function (e) {
                return !(e.from === parts[0] && (e.port || 'out') === parts[1] && e.to === parts[2]);
            });
            Flow.selectedEdge = null;
            drawEdges();
            return;
        }
        if (!Flow.selectedId) return;
        var node = findNode(Flow.selectedId);
        if (!node) return;
        if (node.type === 'trigger' && Flow.nodes.filter(function (n) { return n.type === 'trigger'; }).length <= 1) {
            if (window.APP && APP.toast) APP.toast('Keep at least one trigger', 'warning');
            return;
        }
        Flow.nodes = Flow.nodes.filter(function (n) { return n.id !== Flow.selectedId; });
        Flow.edges = Flow.edges.filter(function (e) { return e.from !== Flow.selectedId && e.to !== Flow.selectedId; });
        Flow.selectedId = null;
        renderNodes();
        renderInspector();
    }

    function clientToCanvas(clientX, clientY) {
        var wrap = document.getElementById('flowCanvasWrap') || document.querySelector('.flow-canvas-wrap');
        if (!wrap) return { x: 0, y: 0 };
        var rect = wrap.getBoundingClientRect();
        return {
            x: (clientX - rect.left - Flow.panX) / Flow.scale,
            y: (clientY - rect.top - Flow.panY) / Flow.scale
        };
    }

    function upsertEdge(from, to, port) {
        port = port || 'out';
        Flow.edges = Flow.edges.filter(function (e) { return !(e.from === from && (e.port || 'out') === port); });
        if (from === to) return;
        Flow.edges.push({ from: from, to: to, port: port });
        Flow.selectedEdge = edgeKey({ from: from, to: to, port: port });
        drawEdges();
    }

    function exportGraph() {
        return { nodes: Flow.nodes, edges: Flow.edges };
    }

    function buildTriggerConfig(triggerNode) {
        var d = (triggerNode && triggerNode.data) || {};
        var cfg = {};
        TRIGGER_CONFIG_KEYS.forEach(function (k) {
            if (d[k] !== undefined && d[k] !== null && String(d[k]) !== '') {
                cfg[k] = d[k];
            }
        });
        if (cfg.keyword && !cfg.content) cfg.content = cfg.keyword;
        if (cfg.shopify_topic && !cfg.event_topic) cfg.event_topic = cfg.shopify_topic;
        return cfg;
    }

    function save() {
        var $root = $('#flowBuilder');
        var url = $root.data('save-url');
        var triggerNode = Flow.nodes.find(function (n) { return n.type === 'trigger'; });
        var payload = {
            flow_graph: exportGraph(),
            name: $('#flowName').val(),
            is_active: $('#flowActive').is(':checked') ? 1 : 0,
            trigger_type: triggerNode && triggerNode.data ? triggerNode.data.trigger_type : undefined,
            trigger_config: buildTriggerConfig(triggerNode)
        };

        var req = $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json',
            data: JSON.stringify(payload),
            headers: {
                'X-CSRF-TOKEN': String($root.data('csrf') || $('meta[name="csrf-token"]').attr('content') || ''),
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        req.done(function (res) {
            if (window.APP && APP.toast) APP.toast((res && res.message) || 'Workflow saved', 'success');
            else alert('Workflow saved');
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Save failed';
            if (window.APP && APP.toast) APP.toast(msg, 'error');
            else alert(msg);
        });
    }

    function bind() {
        var $wrap = $('#flowCanvasWrap, .flow-canvas-wrap');
        var wrapEl = document.getElementById('flowCanvasWrap') || document.querySelector('.flow-canvas-wrap');

        $('.palette-item').on('dragstart', function (e) {
            var dt = e.originalEvent.dataTransfer;
            dt.setData('text/plain', JSON.stringify({
                palette: $(this).data('palette'),
                trigger: $(this).data('trigger'),
                condition: $(this).data('condition'),
                action: $(this).data('action')
            }));
            $(this).addClass('dragging');
        }).on('dragend', function () {
            $(this).removeClass('dragging');
        });

        $wrap.on('dragover', function (e) {
            e.preventDefault();
            $(this).addClass('drop-target');
        });
        $wrap.on('dragleave', function () { $(this).removeClass('drop-target'); });
        $wrap.on('drop', function (e) {
            e.preventDefault();
            $(this).removeClass('drop-target');
            var raw = e.originalEvent.dataTransfer.getData('text/plain');
            try {
                var p = JSON.parse(raw);
                var pt = clientToCanvas(e.originalEvent.clientX, e.originalEvent.clientY);
                var x = snap(pt.x - 100);
                var y = snap(pt.y - 30);
                var extra = p.trigger || p.condition || p.action;
                addNodeFromPalette(p.palette, extra, Math.max(20, x), Math.max(20, y));
            } catch (err) {
                // ignore
            }
        });

        $('#flowCanvas').on('mousedown', '.flow-node', function (e) {
            if ($(e.target).closest('.flow-port').length) return;
            if (Flow.spaceDown || e.button === 1) return;
            var id = $(this).data('id');
            Flow.selectedEdge = null;
            selectNode(id);
            var node = findNode(id);
            var pt = clientToCanvas(e.clientX, e.clientY);
            Flow.dragNode = {
                id: id,
                ox: pt.x - node.x,
                oy: pt.y - node.y
            };
            $(this).addClass('dragging-node');
            e.preventDefault();
        });

        $wrap.on('mousedown', function (e) {
            var isPan = Flow.spaceDown || e.button === 1 || ($(e.target).is('#flowCanvas, #flowEdges, #flowViewport, .flow-canvas-wrap, .flow-viewport') && !$(e.target).closest('.flow-node, .flow-port').length);
            if (!isPan) return;
            e.preventDefault();
            Flow.pan = { x: e.clientX - Flow.panX, y: e.clientY - Flow.panY };
            $wrap.addClass('panning');
            if (!$(e.target).closest('.flow-node').length) {
                Flow.selectedId = null;
                Flow.selectedEdge = null;
                renderNodes();
                renderInspector();
            }
        });

        $(document).on('mousemove', function (e) {
            if (Flow.pan) {
                Flow.panX = e.clientX - Flow.pan.x;
                Flow.panY = e.clientY - Flow.pan.y;
                applyViewport();
                return;
            }
            if (Flow.dragNode) {
                var node = findNode(Flow.dragNode.id);
                if (!node) return;
                var pt = clientToCanvas(e.clientX, e.clientY);
                node.x = Math.max(10, snap(pt.x - Flow.dragNode.ox));
                node.y = Math.max(10, snap(pt.y - Flow.dragNode.oy));
                $('.flow-node[data-id="' + node.id + '"]').css({ left: node.x, top: node.y });
                resizeCanvas();
                drawEdges();
            }
            if (Flow.linkFrom) {
                var cpt = clientToCanvas(e.clientX, e.clientY);
                drawEdges({
                    a: Flow.linkFrom.pt,
                    b: { x: cpt.x, y: cpt.y }
                });
                var $near = $(e.target).closest('.flow-port.in');
                $('.flow-port.in').removeClass('link-target');
                if ($near.length) $near.addClass('link-target');
            }
        });

        $(document).on('mouseup', function (e) {
            if (Flow.pan) {
                Flow.pan = null;
                $wrap.removeClass('panning');
            }
            $('.flow-node').removeClass('dragging-node');
            Flow.dragNode = null;
            if (Flow.linkFrom) {
                var $t = $(e.target).closest('.flow-port.in');
                if ($t.length) {
                    upsertEdge(Flow.linkFrom.node, $t.data('node'), Flow.linkFrom.port);
                }
                Flow.linkFrom = null;
                $('.flow-port').removeClass('linking link-target');
                $wrap.removeClass('linking');
                drawEdges();
            }
        });

        $('#flowCanvas').on('mousedown', '.flow-port.out, .flow-port.true, .flow-port.false', function (e) {
            e.stopPropagation();
            e.preventDefault();
            var nodeId = $(this).data('node');
            var port = $(this).data('port');
            var pt = portCenter(nodeId, port);
            Flow.linkFrom = { node: nodeId, port: port, pt: pt };
            Flow.selectedEdge = null;
            $(this).addClass('linking');
            $wrap.addClass('linking');
        });

        $('#flowEdges').on('mousedown', '.flow-edge-hit, .flow-edge', function (e) {
            e.stopPropagation();
            Flow.selectedId = null;
            Flow.selectedEdge = $(this).attr('data-edge-key');
            renderNodes();
            renderInspector();
            drawEdges();
        });

        $(document).on('keydown', function (e) {
            if (e.code === 'Space' && !$(e.target).is('input,textarea,select')) {
                Flow.spaceDown = true;
                $wrap.addClass('can-pan');
                e.preventDefault();
            }
            if ((e.key === 'Delete' || e.key === 'Backspace') && !$(e.target).is('input,textarea,select')) {
                deleteSelected();
            }
            if ((e.key === '+' || e.key === '=') && (e.ctrlKey || e.metaKey)) {
                setScale(Flow.scale + 0.1);
                e.preventDefault();
            }
            if ((e.key === '-' || e.key === '_') && (e.ctrlKey || e.metaKey)) {
                setScale(Flow.scale - 0.1);
                e.preventDefault();
            }
        });
        $(document).on('keyup', function (e) {
            if (e.code === 'Space') {
                Flow.spaceDown = false;
                $wrap.removeClass('can-pan');
            }
        });

        if (wrapEl) {
            wrapEl.addEventListener('wheel', function (e) {
                if (!(e.ctrlKey || e.metaKey)) return;
                e.preventDefault();
                var delta = e.deltaY > 0 ? -0.08 : 0.08;
                setScale(Flow.scale + delta, e.clientX, e.clientY);
            }, { passive: false });
        }

        $('#inspectorBody').on('change input', '.insp', function () {
            applyInspector($(this));
        });
        $('#inspectorBody').on('change', '.insp-check', function () {
            var node = findNode(Flow.selectedId);
            if (!node) return;
            node.data = node.data || {};
            node.data[$(this).data('k')] = $(this).is(':checked');
            renderNodes();
            $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
        });
        $('#inspectorBody').on('change', '.insp-value-check', function () {
            syncWebhookValuesFromChecks();
        });
        $('#inspectorBody').on('change input', '.insp-header', function () {
            var node = findNode(Flow.selectedId);
            if (!node) return;
            node.data = node.data || {};
            node.data.header = node.data.header || { key: 'Content-Type', value: 'application/json' };
            node.data.header[$(this).data('hk')] = $(this).val();
        });
        $('#inspectorBody').on('click', '#inspAddParam', function () {
            var node = findNode(Flow.selectedId);
            if (!node) return;
            var key = String($('#inspNewParam').val() || '').trim();
            if (!key) return;
            node.data = node.data || {};
            var values = Array.isArray(node.data.values) ? node.data.values.slice() : [];
            if (values.indexOf(key) < 0) values.push(key);
            node.data.values = values;
            if ((Flow.meta.attributes || []).indexOf(key) < 0) {
                Flow.meta.attributes = (Flow.meta.attributes || []).concat([key]);
            }
            $('#inspNewParam').val('');
            renderInspector();
            renderNodes();
            $('.flow-node[data-id="' + node.id + '"]').addClass('selected');
        });

        $('#btnDeleteNode').on('click', deleteSelected);
        $('#btnFlowSave').on('click', save);
        $('#btnFlowZoomFit').on('click', fitView);
        $('#btnFlowZoomIn').on('click', function () { setScale(Flow.scale + 0.1); });
        $('#btnFlowZoomOut').on('click', function () { setScale(Flow.scale - 0.1); });
    }

    $(function () {
        if (!$('#flowBuilder').length) return;
        loadGraph();
        renderNodes();
        bind();
        applyViewport();
        var t = Flow.nodes.find(function (n) { return n.type === 'trigger'; });
        if (t && t.data) $('#flowTriggerPill').text(TRIGGER_LABELS[t.data.trigger_type] || t.data.trigger_type || '');
        setTimeout(fitView, 60);
    });

    window.WorkflowFlow = Flow;
})(window, jQuery);
