// Deliberately NOT flarum-webpack-config: its runtime prologue references
// flarum.reg unconditionally, which doesn't exist on Flarum 1.x and would kill
// the bundle at load time there. Birdseye imports nothing from flarum/* (it
// resolves the core globals at runtime via src/common/compat.ts, so one
// artifact runs on both majors), so a plain webpack build with a TypeScript
// loader is all it needs.
//
// output.library commonjs2 matters: Flarum (both majors) wraps each extension
// bundle as `var module = {}; <bundle>; flarum.extensions['<id>'] =
// module.exports;` and boot reads that object, so the bundle must assign
// module.exports or the extension registers as undefined and the whole frontend
// fails to boot.
//
// mithril is the one external: Flarum exposes it as the global `m`, so the sole
// `import m from 'mithril'` maps to window.m rather than being bundled.
const path = require('path');

module.exports = {
  entry: {
    forum: './src/forum.ts',
    admin: './src/admin.ts',
  },
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].js',
    library: { type: 'commonjs2' },
  },
  externalsType: 'window',
  externals: {
    mithril: 'm',
  },
  resolve: {
    extensions: ['.ts', '.tsx', '.js'],
  },
  module: {
    rules: [
      {
        test: /\.tsx?$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: [
              ['@babel/preset-env', { targets: { esmodules: true } }],
              ['@babel/preset-typescript', { allowDeclareFields: true }],
            ],
          },
        },
      },
    ],
  },
  devtool: 'source-map',
};
