window.KeyframePreviewGate = (function () {
    function create() {
        var sequence = 0;
        var current = null;
        var controller = null;

        function abort() {
            if (controller !== null) {
                controller.abort();
                controller = null;
            }
        }

        return {
            begin: function (sceneId, AbortControllerType) {
                abort();
                sequence += 1;
                current = { sequence: sequence, sceneId: String(sceneId) };
                controller = typeof AbortControllerType === 'function' ? new AbortControllerType() : null;

                return { sequence: current.sequence, sceneId: current.sceneId, signal: controller ? controller.signal : undefined };
            },

            cancel: function () {
                abort();
                sequence += 1;
                current = null;
            },

            isCurrent: function (ticket) {
                return current !== null && ticket !== null && ticket.sequence === current.sequence;
            },

            accepts: function (ticket, preview) {
                if (!this.isCurrent(ticket) || preview === null || typeof preview !== 'object') {
                    return false;
                }

                return String(preview.scene_id) === ticket.sceneId;
            },

            renderable: function (ticket, preview) {
                return this.accepts(ticket, preview) && preview.render_ready === true;
            }
        };
    }

    return { create: create };
})();
