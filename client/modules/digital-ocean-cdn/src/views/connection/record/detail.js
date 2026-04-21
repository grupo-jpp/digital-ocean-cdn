Espo.define(
  "digital-ocean-cdn:views/connection/record/detail",
  "views/connection/record/detail",
  (Dep) => {
    return Dep.extend({
      setup() {
        console.log(
          "[DO-CDN] connection detail setup, type=",
          this.model.get("type")
        );
        Dep.prototype.setup.call(this);
        console.log(
          "[DO-CDN] after parent setup, detailLayout=",
          this.detailLayout
        );

        if (this.model.get("type") === "doSpaces") {
          this.applyDoSpacesLayout();
          console.log("[DO-CDN] applied DO layout", this.detailLayout);
        }

        this.listenTo(this.model, "change:type", () => {
          console.log("[DO-CDN] type changed to", this.model.get("type"));
          if (this.model.get("type") === "doSpaces") {
            this.applyDoSpacesLayout();
            this.reRender();
          } else if (this.model.previous("type") === "doSpaces") {
            this.detailLayout = null;
            this.reRender();
          }
        });
      },

      applyDoSpacesLayout() {
        this.detailLayout = [
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
      },
    });
  }
);
