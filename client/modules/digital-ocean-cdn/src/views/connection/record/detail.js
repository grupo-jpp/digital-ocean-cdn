Espo.define(
  "digital-ocean-cdn:views/connection/record/detail",
  "views/connection/record/detail",
  (Dep) => {
    return Dep.extend({
      setup() {
        Dep.prototype.setup.call(this);

        this.detailLayout = [
          {
            label: "Details",
            rows: [
              [{ name: "name" }, { name: "type" }],
              [{ name: "doSpacesEndpoint" }, { name: "doSpacesRegion" }],
              [{ name: "doSpacesAccessKey" }, { name: "doSpacesSecretKey" }],
            ],
          },
        ];
      },
    });
  }
);
