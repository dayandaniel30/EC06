import React, { Component } from "react";
import { Redirect } from "react-router-dom";

class Logout extends Component {
  componentDidMount() {
    // Clear the token
    localStorage.removeItem("token");
  }

  render() {
    return <Redirect to="/" />;
  }
}

export default Logout;