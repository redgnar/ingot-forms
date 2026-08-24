// The `bootstrap` kit wearing "material": one stylesheet, and then everything the
// kit loads whatever it is wearing. A skin is CSS and nothing else — if one ever
// needs a line of JavaScript or a class in the markup, it has stopped being a
// skin and become a second kit.
import 'bootswatch/dist/materia/bootstrap.min.css';
import '../styles/skins/material.css';
import './kit.js';
