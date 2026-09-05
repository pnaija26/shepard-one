import api from './client'

export default {
  list(params = {}) {
    return api.get('/groups', { params })
  },

  create(payload) {
    return api.post('/groups', payload)
  },

  show(id) {
    return api.get(`/groups/${id}`)
  },

  update(id, payload) {
    return api.put(`/groups/${id}`, payload)
  },

  activate(id) {
    return api.post(`/groups/${id}/activate`)
  },

  assignMember(groupId, payload) {
    return api.post(`/groups/${groupId}/members`, payload)
  },

  transferMember(groupId, membershipId, payload) {
    return api.post(`/groups/${groupId}/members/${membershipId}/transfer`, payload)
  },

  removeMember(groupId, membershipId, payload) {
    return api.post(`/groups/${groupId}/members/${membershipId}/remove`, payload)
  },

  submitJoinRequest(groupId, payload) {
    return api.post(`/groups/${groupId}/join-requests`, payload)
  },

  reviewJoinRequest(requestId, payload) {
    return api.post(`/group-join-requests/${requestId}/review`, payload)
  },

  listMeetings(groupId) {
    return api.get(`/groups/${groupId}/meetings`)
  },

  scheduleMeeting(groupId, payload) {
    return api.post(`/groups/${groupId}/meetings`, payload)
  },

  showMeeting(meetingId) {
    return api.get(`/group-meetings/${meetingId}`)
  },

  recordMeeting(meetingId, payload) {
    return api.post(`/group-meetings/${meetingId}/record`, payload)
  },

  correctAttendance(attendanceId, payload) {
    return api.post(`/group-meeting-attendance/${attendanceId}/correct`, payload)
  },

  evaluateFollowUps(meetingId, payload) {
    return api.post(`/group-meetings/${meetingId}/evaluate-follow-ups`, payload)
  },

  meetingDashboard(groupId) {
    return api.get(`/groups/${groupId}/meeting-dashboard`)
  },
}
