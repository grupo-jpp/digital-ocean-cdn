Espo.define(
  "digital-ocean-cdn:views/connection/record/detail",
  "views/connection/record/detail",
  (Dep) => {
    return Dep.extend({
      setup() {
        let _gl = null;
        Object.defineProperty(this, "gridLayout", {
          get: () => _gl,
          set: (v) => {
            console.log("[DO-CDN] gridLayout SET", v);
            console.trace();
            _gl = v;
          },
          configurable: true,
        });

        Dep.prototype.setup.call(this);

        this.listenTo(this.model, "change:type", () => {
          this.gridLayout = null;
          this.reRender();
        });
      },

      getGridLayout(callback) {
        console.log(
          "[DO-CDN] getGridLayout called, type=",
          this.model.get("type")
        );
        if (this.model.get("type") === "doSpaces") {
          const detailLayout = [
            {
              label: "Details",
              rows: [
                [{ name: "name" }, { name: "type" }],
                [{ name: "doSpacesEndpoint" }, { name: "doSpacesRegion" }],
                [{ name: "doSpacesAccessKey" }, { name: "doSpacesSecretKey" }],
                [{ name: "doSpacesBucket" }, { name: "doSpacesCdnEndpoint" }],
              ],
            },
          ];

          this.gridLayout = {
            type: this.gridLayoutType || "record",
            layout: this.convertDetailLayout(detailLayout),
          };

          callback(this.gridLayout);
          return;
        }
        Dep.prototype.getGridLayout.call(this, callback);
      },
    });
  }
);
