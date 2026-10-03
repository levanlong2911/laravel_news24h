window.KeyframePreviewGate = (function () {
    function wanted(choice) {
        return typeof choice === 'string' && choice !== '' && choice !== 'none' ? choice : null;
    }

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
            begin: function (sceneId, choice, AbortControllerType) {
                abort();
                sequence += 1;
                current = { sequence: sequence, sceneId: String(sceneId), choice: wanted(choice) };
                controller = typeof AbortControllerType === 'function' ? new AbortControllerType() : null;

                return { sequence: current.sequence, sceneId: current.sceneId, choice: current.choice, signal: controller ? controller.signal : undefined };
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

                if (String(preview.scene_id) !== ticket.sceneId) {
                    return false;
                }

                var chosen = preview.space_source ? wanted(preview.space_source.chosen) : null;

                return chosen === ticket.choice;
            },

            renderable: function (ticket, preview) {
                return this.accepts(ticket, preview) && preview.render_ready === true;
            }
        };
    }

    return { create: create };
})();
